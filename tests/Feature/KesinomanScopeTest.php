<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Krama\KramaManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class KesinomanScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kelian;

    private int $tenant;

    private int $unitA;

    private int $unitB;

    private int $householdInA;

    private int $householdInB;

    private int $personInA;

    private int $personInB;

    private int $personSiblingInA;

    private FinanceManager $finance;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Refusing to refresh anything except banjarv2_testing on MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        $this->tenant = app(TenantManager::class)->create($super, ['name' => 'Banjar Kesinoman', 'slug' => 'kesinoman-qa', 'admin_name' => 'Admin QA', 'admin_email' => 'admin-kesinoman@example.test', 'admin_password' => 'Test-Password-12345', 'admin_password_confirmation' => 'Test-Password-12345']);
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->whereIn('code', ['KRAMA', 'FINANCE'])->update(['status' => 'ACTIVE']);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->tenant));
        $this->admin = User::where('email', 'admin-kesinoman@example.test')->firstOrFail();
        $this->kelian = $this->role('KELIAN');
        $this->unitA = DB::table('organizational_units')->insertGetId(['banjar_id' => $this->tenant, 'name' => 'Tempekan A', 'type' => 'TEMPEKAN', 'created_at' => now(), 'updated_at' => now()]);
        $this->unitB = DB::table('organizational_units')->insertGetId(['banjar_id' => $this->tenant, 'name' => 'Tempekan B', 'type' => 'TEMPEKAN', 'created_at' => now(), 'updated_at' => now()]);
        $krama = app(KramaManager::class);
        $this->householdInA = $krama->household($this->admin, null, ['code' => 'KK-A-01', 'status' => 'ACTIVE']);
        $this->householdInB = $krama->household($this->admin, null, ['code' => 'KK-B-01', 'status' => 'ACTIVE']);
        DB::table('household_unit_memberships')->insert(['banjar_id' => $this->tenant, 'household_id' => $this->householdInA, 'unit_id' => $this->unitA, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('household_unit_memberships')->insert(['banjar_id' => $this->tenant, 'household_id' => $this->householdInB, 'unit_id' => $this->unitB, 'created_at' => now(), 'updated_at' => now()]);
        $this->personInA = $krama->person($this->admin, null, ['household_id' => $this->householdInA, 'name' => 'Made di Unit A', 'status' => 'ACTIVE']);
        $this->personSiblingInA = $krama->person($this->admin, null, ['household_id' => $this->householdInA, 'name' => 'Nyoman di Unit A', 'status' => 'ACTIVE']);
        $this->personInB = $krama->person($this->admin, null, ['household_id' => $this->householdInB, 'name' => 'Wayan di Unit B', 'status' => 'ACTIVE']);
        $this->finance = app(FinanceManager::class);
    }

    private function role(string $code, ?int $unit = null, bool $descendants = false): User
    {
        $user = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
            'scope_type' => $unit ? 'ORGANIZATIONAL_UNIT' : 'BANJAR', 'unit_id' => $unit, 'include_descendants' => $descendants]);

        return $user;
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function forbidden(callable $action, int $status = 403): void
    {
        try {
            $action();
            $this->fail('Expected HTTP failure.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_scoped_kesinoman_issues_only_within_own_unit_for_all_active_target(): void
    {
        $scoped = $this->role('KESINOMAN', $this->unitA);
        $type = $this->finance->billingType($scoped, ['name' => 'Iuran Unit A', 'kind' => 'IURAN', 'default_amount' => '25000']);
        $event = $this->finance->event($scoped, ['billing_type_id' => $type, 'title' => 'Iuran September', 'reference' => (string) Str::uuid()]);
        $row = DB::table('billing_events')->find($event);
        $this->assertSame(1, $row->target_kk_count);
        $this->finance->issue($scoped, $event);
        $this->assertSame([$this->householdInA], DB::table('bills')->where('billing_event_id', $event)->pluck('kk_id')->all());
    }

    public function test_scoped_kesinoman_cannot_target_a_unit_outside_access(): void
    {
        $scoped = $this->role('KESINOMAN', $this->unitA);
        $type = $this->finance->billingType($scoped, ['name' => 'Iuran Terbatas', 'kind' => 'IURAN', 'default_amount' => '25000']);
        $this->invalid(fn () => $this->finance->event($scoped, ['billing_type_id' => $type, 'title' => 'Coba unit lain', 'reference' => (string) Str::uuid(), 'target' => 'UNIT', 'unit_id' => $this->unitB]));
        $this->assertDatabaseCount('billing_events', 0);
    }

    public function test_scoped_kesinoman_bulk_subjects_rejects_target_outside_scope_atomically(): void
    {
        $scoped = $this->role('KESINOMAN', $this->unitA);
        $type = $this->finance->billingType($scoped, ['name' => 'Dedosan Unit A', 'kind' => 'DEDOSAN', 'default_amount' => '50000']);
        $event = $this->finance->event($scoped, ['billing_type_id' => $type, 'title' => 'Dedosan campuran', 'reference' => (string) Str::uuid()]);
        $this->invalid(fn () => $this->finance->bulkSubjects($scoped, $event, ['krama_ids' => [$this->personInA, $this->personInB], 'reason' => 'Campuran unit dalam satu batch']));
        $this->assertDatabaseCount('billing_event_subjects', 0);
    }

    public function test_bulk_subjects_rejects_same_household_conflict_atomically_and_nothing_is_written(): void
    {
        $kesinoman = $this->role('KESINOMAN');
        $type = $this->finance->billingType($kesinoman, ['name' => 'Dedosan Konflik', 'kind' => 'DEDOSAN', 'default_amount' => '50000']);
        $event = $this->finance->event($kesinoman, ['billing_type_id' => $type, 'title' => 'Dedosan konflik satu KK', 'reference' => (string) Str::uuid()]);
        $this->invalid(fn () => $this->finance->bulkSubjects($kesinoman, $event, ['krama_ids' => [$this->personInA, $this->personSiblingInA, $this->personInB], 'reason' => 'Dua orang satu KK dalam satu batch']));
        $this->assertDatabaseCount('billing_event_subjects', 0);
    }

    public function test_bulk_subjects_issues_all_valid_selections_in_one_atomic_call(): void
    {
        $kesinoman = $this->role('KESINOMAN');
        $type = $this->finance->billingType($kesinoman, ['name' => 'Dedosan Massal', 'kind' => 'DEDOSAN', 'default_amount' => '50000']);
        $event = $this->finance->event($kesinoman, ['billing_type_id' => $type, 'title' => 'Dedosan massal', 'reference' => (string) Str::uuid()]);
        $this->finance->bulkSubjects($kesinoman, $event, ['krama_ids' => [$this->personInA, $this->personInB], 'reason' => 'Tidak hadir tanpa keterangan']);
        $this->assertSame(2, DB::table('billing_event_subjects')->where('billing_event_id', $event)->count());
        $this->finance->review($this->kelian, $event, ['action' => 'approve', 'review_note' => 'Diperiksa bersama pengurus']);
        $this->finance->issue($kesinoman, $event);
        $this->assertSame(2, DB::table('bills')->where('billing_event_id', $event)->count());
    }

    public function test_petengen_alone_cannot_issue_or_add_dedosan_subjects(): void
    {
        $petengen = $this->role('PETENGEN');
        $type = $this->finance->billingType($this->role('KESINOMAN'), ['name' => 'Iuran Cek Petengen', 'kind' => 'IURAN', 'default_amount' => '25000']);
        $this->forbidden(fn () => $this->finance->event($petengen, ['billing_type_id' => $type, 'title' => 'Coba oleh Petengen', 'reference' => (string) Str::uuid()]));
        $dedosanType = $this->finance->billingType($this->role('KESINOMAN'), ['name' => 'Dedosan Cek Petengen', 'kind' => 'DEDOSAN', 'default_amount' => '50000']);
        $kesinoman = $this->role('KESINOMAN');
        $event = $this->finance->event($kesinoman, ['billing_type_id' => $dedosanType, 'title' => 'Dedosan cek', 'reference' => (string) Str::uuid()]);
        $this->forbidden(fn () => $this->finance->bulkSubjects($petengen, $event, ['krama_ids' => [$this->personInA], 'reason' => 'Petengen mencoba menetapkan']));
        $this->forbidden(fn () => $this->finance->subject($petengen, $event, ['subject_krama_id' => $this->personInA, 'reason' => 'Petengen mencoba menetapkan satu-satu']));
    }
}

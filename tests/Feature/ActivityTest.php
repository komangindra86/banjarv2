<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Activity\ActivityAccess;
use App\Modules\Activity\ActivityManager;
use App\Modules\Activity\DedosanCandidateManager;
use App\Modules\Activity\MinutesManager;
use App\Modules\Finance\FinanceManager;
use App\Modules\Website\ContentManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $tenant;

    private User $admin;

    private User $penyarikan;

    private User $petengen;

    private User $kesinoman;

    private User $kelian;

    private array $unit = [];

    private array $kk = [];

    private array $p = [];

    private ActivityManager $activities;

    private string $host = 'http://financeqa.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Activity tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fixture = FinanceFixture::create();
        $this->tenant = $this->fixture['tenant'];
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'ACTIVITY')->update(['status' => 'ACTIVE']);
        $this->admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $this->petengen = User::findOrFail($this->fixture['users']['PETENGEN']);
        $this->kesinoman = User::findOrFail($this->fixture['users']['KESINOMAN']);
        $this->kelian = User::findOrFail($this->fixture['users']['KELIAN']);
        $this->penyarikan = $this->role('PENYARIKAN');
        $this->activities = app(ActivityManager::class);
        $this->seedStructure();
        $this->context();
    }

    private function context(?int $tenant = null): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant ?? $this->tenant));
    }

    private function role(string $code, ?int $unit = null, bool $descendants = false, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $member = DB::table('banjar_user_memberships')->where('banjar_id', $this->tenant)->where('user_id', $user->id)->value('id')
            ?? DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
            'scope_type' => $unit ? 'ORGANIZATIONAL_UNIT' : 'BANJAR', 'unit_id' => $unit, 'include_descendants' => $descendants]);

        return $user;
    }

    private function row(string $table, array $data): int
    {
        return DB::table($table)->insertGetId($data + ['banjar_id' => $this->tenant, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedStructure(): void
    {
        $this->unit['T1'] = $this->row('organizational_units', ['type' => 'TEMPEKAN', 'name' => 'Tempekan Kangin']);
        $this->unit['R1'] = $this->row('organizational_units', ['type' => 'REGU', 'name' => 'Regu 1', 'parent_id' => $this->unit['T1']]);
        $this->unit['T2'] = $this->row('organizational_units', ['type' => 'TEMPEKAN', 'name' => 'Tempekan Kauh']);
        foreach (['A' => ['T1', ['Made', 'Ketut']], 'B' => ['R1', ['Wayan', 'Nyoman']], 'C' => ['T2', ['Putu']]] as $code => [$unit, $names]) {
            $this->kk[$code] = $this->row('households', ['code' => 'KK-'.$code, 'status' => 'ACTIVE']);
            $this->row('household_unit_memberships', ['household_id' => $this->kk[$code], 'unit_id' => $this->unit[$unit]]);
            foreach ($names as $name) {
                $this->p[$name] = $this->row('krama', ['household_id' => $this->kk[$code], 'name' => $name, 'status' => 'ACTIVE']);
            }
            DB::table('households')->where('id', $this->kk[$code])->update(['head_krama_id' => $this->p[$names[0]]]);
        }
        // Kelian is also krama Putu, so a ROLE target resolves to a person.
        DB::table('krama')->where('id', $this->p['Putu'])->update(['user_id' => $this->kelian->id]);
    }

    private function activity(array $settings = [], string $title = 'Paruman Krama', ?string $starts = null): int
    {
        $this->context();
        $id = app(ContentManager::class)->save($this->admin, 'agenda', null, ['title' => $title, 'status' => 'PUBLISHED', 'starts_at' => $starts ?? now('Asia/Makassar')->addDay()->format('Y-m-d H:i'), 'location' => 'Bale Banjar']);
        $this->activities->configure($this->admin, $id, $settings + ['kind' => 'PARUMAN', 'visibility' => 'PUBLIC', 'attendance_basis' => 'PERSON', 'allow_representative' => '1']);

        return $id;
    }

    private function started(int $id): void
    {
        DB::table('activities')->where('id', $id)->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2)]);
    }

    private function participant(int $activity, string $name): int
    {
        return (int) DB::table('activity_participants')->where('activity_id', $activity)->where('krama_id', $this->p[$name])->value('id');
    }

    private function slot(int $activity, string $kk): int
    {
        return (int) DB::table('activity_participants')->where('activity_id', $activity)->where('household_id', $this->kk[$kk])->value('id');
    }

    private function rejected(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Action unexpectedly accepted');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());
        }
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Validation unexpectedly passed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function tenantLogin(User $user): self
    {
        return $this->actingAs($user, 'web')->withSession(['auth_host' => 'financeqa.localhost', 'auth_context' => (string) $this->tenant]);
    }

    public function test_targets_are_a_deduplicated_union_for_person_and_household_basis(): void
    {
        $id = $this->activity();
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $this->unit['T1'], 'include_descendants' => '1']);
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'SELECTED_KRAMA', 'krama_id' => $this->p['Made']]);
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'SELECTED_KRAMA', 'krama_id' => $this->p['Made']]);
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ROLE', 'role_id' => DB::table('roles')->where('code', 'KELIAN')->value('id')]);
        $this->assertSame(3, DB::table('activity_targets')->where('activity_id', $id)->count());
        $this->assertSame(['added' => 5, 'removed' => 0, 'total' => 5], $this->activities->generate($this->admin, $id));
        $this->assertSame(5, DB::table('activity_participants')->where('activity_id', $id)->count());
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ALL_KRAMA']);
        $this->assertSame(8, $this->activities->generate($this->admin, $id)['total']);
        $this->assertSame(8, DB::table('activity_participants')->where('activity_id', $id)->distinct()->count('krama_id'));

        $household = $this->activity(['attendance_basis' => 'HOUSEHOLD'], 'Ngayah');
        $this->activities->addTarget($this->admin, $household, ['target_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $this->unit['T1']]);
        $this->assertSame(1, $this->activities->generate($this->admin, $household)['total']);
        // An individual unit member brings their KK in once, even if targeted twice.
        $this->row('krama_unit_memberships', ['krama_id' => $this->p['Putu'], 'unit_id' => $this->unit['T1']]);
        $this->activities->addTarget($this->admin, $household, ['target_type' => 'SELECTED_KRAMA', 'krama_id' => $this->p['Putu']]);
        $this->assertSame(2, $this->activities->generate($this->admin, $household)['total']);
        $this->assertSame([$this->kk['A'], $this->kk['C']], DB::table('activity_participants')->where('activity_id', $household)->orderBy('household_id')->pluck('household_id')->map(fn ($v) => (int) $v)->all());
        $this->invalid(fn () => $this->activities->configure($this->admin, $household, ['kind' => 'NGAYAH', 'visibility' => 'PUBLIC', 'attendance_basis' => 'PERSON', 'allow_representative' => '1']), 'attendance_basis');
    }

    public function test_regenerating_preserves_records_and_refuses_to_drop_recorded_slots(): void
    {
        $id = $this->activity();
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $this->unit['T1'], 'include_descendants' => '1']);
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'SELECTED_KRAMA', 'krama_id' => $this->p['Putu']]);
        $this->activities->generate($this->admin, $id);
        $this->activities->record($this->admin, $id, $this->participant($id, 'Made'), ['status' => 'HADIR']);
        $this->activities->record($this->admin, $id, $this->participant($id, 'Putu'), ['status' => 'IZIN', 'note' => 'Upacara keluarga']);
        $unitTarget = DB::table('activity_targets')->where('activity_id', $id)->where('target_type', 'ORGANIZATIONAL_UNIT')->value('id');
        $this->activities->removeTarget($this->admin, $id, $unitTarget);
        $this->invalid(fn () => $this->activities->generate($this->admin, $id), 'target_type');
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'SELECTED_KRAMA', 'krama_id' => $this->p['Made']]);
        $this->assertSame(['added' => 0, 'removed' => 3, 'total' => 2], $this->activities->generate($this->admin, $id));
        $this->assertDatabaseHas('activity_participants', ['id' => $this->participant($id, 'Putu'), 'status' => 'IZIN']);
        $this->assertDatabaseHas('activity_participants', ['id' => $this->participant($id, 'Made'), 'status' => 'HADIR', 'recorded_by' => $this->admin->id]);
    }

    public function test_household_representative_rules_and_person_cannot_be_represented(): void
    {
        $id = $this->activity(['attendance_basis' => 'HOUSEHOLD'], 'Gotong royong');
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ALL_HOUSEHOLDS']);
        $this->assertSame(6, $this->activities->generate($this->admin, $id)['total']);
        $slot = $this->slot($id, 'A');
        $this->activities->record($this->admin, $id, $slot, ['status' => 'HADIR', 'attending_krama_id' => $this->p['Ketut']]);
        $this->assertDatabaseHas('activity_participants', ['id' => $slot, 'attending_krama_id' => $this->p['Ketut']]);
        $this->invalid(fn () => $this->activities->record($this->admin, $id, $slot, ['status' => 'HADIR', 'attending_krama_id' => $this->p['Wayan']]), 'attending_krama_id');
        $this->activities->record($this->admin, $id, $slot, ['status' => 'IZIN', 'attending_krama_id' => $this->p['Ketut']]);
        $this->assertDatabaseHas('activity_participants', ['id' => $slot, 'status' => 'IZIN', 'attending_krama_id' => null]);
        $this->activities->configure($this->admin, $id, ['kind' => 'GOTONG_ROYONG', 'visibility' => 'PUBLIC', 'attendance_basis' => 'HOUSEHOLD', 'allow_representative' => '0']);
        $this->invalid(fn () => $this->activities->record($this->admin, $id, $slot, ['status' => 'HADIR', 'attending_krama_id' => $this->p['Ketut']]), 'attending_krama_id');
        $this->activities->record($this->admin, $id, $slot, ['status' => 'HADIR']);
        $this->assertDatabaseHas('activity_participants', ['id' => $slot, 'attending_krama_id' => $this->p['Made']]);

        $person = $this->activity([], 'Rapat pengurus');
        $this->activities->addTarget($this->admin, $person, ['target_type' => 'SELECTED_KRAMA', 'krama_id' => $this->p['Ketut']]);
        $this->activities->generate($this->admin, $person);
        $this->invalid(fn () => $this->activities->record($this->admin, $person, $this->participant($person, 'Ketut'), ['status' => 'HADIR', 'attending_krama_id' => $this->p['Made']]), 'attending_krama_id');
    }

    public function test_scope_module_gate_visibility_and_tenant_isolation(): void
    {
        $id = $this->activity(['attendance_basis' => 'HOUSEHOLD', 'visibility' => 'INTERNAL'], 'Paruman internal');
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ALL_HOUSEHOLDS']);
        $this->activities->generate($this->admin, $id);
        $regu = $this->role('KESINOMAN', $this->unit['R1']);
        $this->assertSame([$this->kk['B']], app(ActivityAccess::class)->participants($regu, $id, 'attendance.record')->pluck('activity_participants.household_id')->map(fn ($v) => (int) $v)->all());
        $this->activities->record($regu, $id, $this->slot($id, 'B'), ['status' => 'HADIR']);
        $this->rejected(fn () => $this->activities->record($regu, $id, $this->slot($id, 'A'), ['status' => 'HADIR']), 404);
        $this->rejected(fn () => $this->activities->verify($this->petengen, $id), 403);
        $this->rejected(fn () => $this->activities->generate($this->petengen, $id), 403);

        $public = $this->activity([], 'Piodalan terbuka');
        $slug = DB::table('activities')->where('id', $id)->value('slug');
        $this->get($this->host.'/agenda/'.$slug)->assertNotFound();
        $this->get($this->host.'/agenda/'.DB::table('activities')->where('id', $public)->value('slug'))->assertOk();

        $super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        $other = app(TenantManager::class)->create($super, ['name' => 'Banjar Lain', 'slug' => 'activity-other', 'admin_name' => 'Lain', 'admin_email' => 'activity-other@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        foreach (['KRAMA', 'ACTIVITY'] as $code) {
            app(TenantManager::class)->module($super, $other, $code, ['status' => 'ACTIVE']);
        }
        $otherAdmin = User::where('email', 'activity-other@example.test')->firstOrFail();
        $this->context($other);
        $this->rejected(fn () => $this->activities->generate($otherAdmin, $id), 404);
        $this->context();
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'ACTIVITY')->update(['status' => 'BASIC']);
        $this->rejected(fn () => $this->activities->record($this->admin, $id, $this->slot($id, 'C'), ['status' => 'HADIR']), 403);
        $this->tenantLogin($this->admin)->get($this->host.'/admin/activities')->assertForbidden();
        $this->get($this->host.'/agenda/'.DB::table('activities')->where('id', $public)->value('slug'))->assertOk();
    }

    public function test_krama_self_excuse_is_limited_to_own_slot_and_never_overrides_staff(): void
    {
        $krama = $this->role('KRAMA');
        DB::table('krama')->where('id', $this->p['Nyoman'])->update(['user_id' => $krama->id]);
        $id = $this->activity([], 'Ngayah pura');
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $this->unit['R1']]);
        $this->activities->generate($this->admin, $id);
        $this->activities->excuse($krama, $id, ['status' => 'IZIN', 'note' => 'Bekerja di luar kota']);
        $this->assertDatabaseHas('activity_participants', ['id' => $this->participant($id, 'Nyoman'), 'status' => 'IZIN', 'source' => 'SELF']);
        $this->assertDatabaseHas('activity_participants', ['id' => $this->participant($id, 'Wayan'), 'status' => 'BELUM_DIVERIFIKASI']);
        $this->activities->record($this->penyarikan, $id, $this->participant($id, 'Nyoman'), ['status' => 'HADIR']);
        $this->rejected(fn () => $this->activities->excuse($krama, $id, ['status' => 'SAKIT', 'note' => 'Mencoba mengubah']), 409);
        $other = $this->activity([], 'Rapat lain');
        $this->activities->addTarget($this->admin, $other, ['target_type' => 'SELECTED_KRAMA', 'krama_id' => $this->p['Made']]);
        $this->activities->generate($this->admin, $other);
        $this->rejected(fn () => $this->activities->excuse($krama, $other, ['status' => 'IZIN', 'note' => 'Bukan peserta']), 404);
        $this->rejected(fn () => $this->activities->record($krama, $id, $this->participant($id, 'Wayan'), ['status' => 'HADIR']), 403);
        // §53: jadwal menampilkan semua kegiatan terbit, tetapi izin/presensi hanya untuk slot milik sendiri.
        $this->tenantLogin($krama)->get($this->host.'/aktivitas')->assertOk()->assertSee('Ngayah pura')->assertSee('Rapat lain')
            ->assertDontSee('/aktivitas/'.$other.'/izin', false);
        $this->tenantLogin($krama)->get($this->host.'/admin/activities')->assertForbidden();
        $this->context();
        DB::table('activities')->where('id', $id)->update(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $this->activities->record($this->penyarikan, $id, $this->participant($id, 'Nyoman'), ['status' => 'BELUM_DIVERIFIKASI']);
        $this->rejected(fn () => $this->activities->excuse($krama, $id, ['status' => 'IZIN', 'note' => 'Terlambat memberi tahu']), 409);
    }

    public function test_verification_locks_attendance_and_creates_candidates_not_bills(): void
    {
        $id = $this->activity([], 'Gotong royong setra');
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $this->unit['T1'], 'include_descendants' => '1']);
        $this->activities->generate($this->admin, $id);
        $this->rejected(fn () => $this->activities->verify($this->penyarikan, $id), 409);
        $this->started($id);
        $this->activities->record($this->penyarikan, $id, $this->participant($id, 'Made'), ['status' => 'HADIR']);
        $this->activities->record($this->penyarikan, $id, $this->participant($id, 'Ketut'), ['status' => 'TIDAK_HADIR']);
        $this->invalid(fn () => $this->activities->verify($this->kelian, $id), 'verify');
        $this->assertSame(2, $this->activities->markRemainingAbsent($this->penyarikan, $id));
        $this->assertSame(2, $this->activities->verify($this->kelian, $id));
        $this->assertSame(2, $this->activities->verify($this->kelian, $id));
        $this->assertDatabaseCount('bills', 0);
        $this->assertSame(1, DB::table('dedosan_candidates')->where('activity_id', $id)->where('household_id', $this->kk['B'])->count());
        $this->assertDatabaseHas('dedosan_candidates', ['activity_id' => $id, 'household_id' => $this->kk['B'], 'absent_count' => 2, 'status' => 'PENDING']);
        // Dedosan hanya dikenakan ke kepala keluarga: yang disarankan selalu kepala keluarga (Made), bukan Ketut yang absen.
        $this->assertDatabaseHas('dedosan_candidates', ['activity_id' => $id, 'household_id' => $this->kk['A'], 'absent_count' => 1, 'suggested_krama_id' => $this->p['Made']]);
        $this->rejected(fn () => $this->activities->record($this->penyarikan, $id, $this->participant($id, 'Ketut'), ['status' => 'HADIR']), 409);
        $this->rejected(fn () => $this->activities->generate($this->admin, $id), 409);
        $this->rejected(fn () => $this->activities->configure($this->admin, $id, ['kind' => 'GENERAL', 'visibility' => 'PUBLIC', 'attendance_basis' => 'PERSON', 'allow_representative' => '1']), 409);
    }

    public function test_bulk_decide_applies_multiple_candidates_atomically(): void
    {
        $id = $this->activity([], 'Ngayah bulk decide');
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $this->unit['T1'], 'include_descendants' => '1']);
        $this->activities->generate($this->admin, $id);
        $this->started($id);
        $this->activities->markRemainingAbsent($this->penyarikan, $id);
        $this->activities->verify($this->penyarikan, $id);
        $candidates = app(DedosanCandidateManager::class);
        $a = (int) DB::table('dedosan_candidates')->where('activity_id', $id)->where('household_id', $this->kk['A'])->value('id');
        $b = (int) DB::table('dedosan_candidates')->where('activity_id', $id)->where('household_id', $this->kk['B'])->value('id');
        // A batch with one invalid decision rejects the whole batch: nothing partially applies.
        $this->invalid(fn () => $candidates->decideBulk($this->kesinoman, $id, ['decisions' => [
            ['candidate_id' => $a, 'decision' => 'SELECT', 'responsible_krama_id' => $this->p['Made']],
            ['candidate_id' => $b, 'decision' => 'SELECT', 'responsible_krama_id' => $this->p['Made']], // Made is not the head of household B
        ]]), 'decisions');
        $this->assertDatabaseHas('dedosan_candidates', ['id' => $a, 'status' => 'PENDING']);
        $this->assertDatabaseHas('dedosan_candidates', ['id' => $b, 'status' => 'PENDING']);
        $candidates->decideBulk($this->kesinoman, $id, ['decisions' => [
            ['candidate_id' => $a, 'decision' => 'SELECT', 'responsible_krama_id' => $this->p['Made']],
            ['candidate_id' => $b, 'decision' => 'SELECT', 'responsible_krama_id' => $this->p['Wayan'], 'note' => 'Wakil KK'],
        ]]);
        $this->assertDatabaseHas('dedosan_candidates', ['id' => $a, 'status' => 'SELECTED', 'responsible_krama_id' => $this->p['Made']]);
        $this->assertDatabaseHas('dedosan_candidates', ['id' => $b, 'status' => 'SELECTED', 'responsible_krama_id' => $this->p['Wayan']]);
    }

    public function test_candidates_become_a_reviewed_dedosan_event_with_one_individual_per_household(): void
    {
        $id = $this->activity([], 'Ngayah piodalan');
        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $this->unit['T1'], 'include_descendants' => '1']);
        $this->activities->generate($this->admin, $id);
        $this->started($id);
        $this->activities->record($this->penyarikan, $id, $this->participant($id, 'Made'), ['status' => 'SAKIT', 'note' => 'Demam']);
        $this->activities->markRemainingAbsent($this->penyarikan, $id);
        $this->activities->verify($this->penyarikan, $id);
        $candidates = app(DedosanCandidateManager::class);
        $a = (int) DB::table('dedosan_candidates')->where('activity_id', $id)->where('household_id', $this->kk['A'])->value('id');
        $b = (int) DB::table('dedosan_candidates')->where('activity_id', $id)->where('household_id', $this->kk['B'])->value('id');
        $this->rejected(fn () => $candidates->decide($this->kelian, $id, $a, ['decision' => 'SELECT', 'responsible_krama_id' => $this->p['Made']]), 403);
        // Kesinoman memutuskan calon dedosan dan menetapkan event (Master Prompt §29/§48); Petengen tidak lagi berwenang.
        $this->rejected(fn () => $candidates->decide($this->petengen, $id, $a, ['decision' => 'SELECT', 'responsible_krama_id' => $this->p['Made']]), 403);
        $this->invalid(fn () => $candidates->decide($this->kesinoman, $id, $b, ['decision' => 'SELECT', 'responsible_krama_id' => $this->p['Made']]), 'responsible_krama_id');
        $this->invalid(fn () => $candidates->decide($this->kesinoman, $id, $a, ['decision' => 'DISMISS']), 'note');
        // Dedosan hanya dikenakan ke kepala keluarga: Made adalah kepala keluarga KK A.
        $candidates->decide($this->kesinoman, $id, $a, ['decision' => 'SELECT', 'responsible_krama_id' => $this->p['Made']]);
        $type = (int) DB::table('billing_types')->where('banjar_id', $this->tenant)->where('kind', 'DEDOSAN')->value('id');
        $this->invalid(fn () => $candidates->createEvent($this->kesinoman, $id, ['billing_type_id' => $type]), 'billing_type_id');
        $candidates->decide($this->kesinoman, $id, $b, ['decision' => 'SELECT', 'responsible_krama_id' => $this->p['Wayan'], 'note' => 'Wakil KK']);
        $event = $candidates->createEvent($this->kesinoman, $id, ['billing_type_id' => $type, 'amount' => '75000']);
        $this->assertSame($event, $candidates->createEvent($this->kesinoman, $id, ['billing_type_id' => $type]));
        $this->assertDatabaseHas('billing_events', ['id' => $event, 'kind' => 'DEDOSAN', 'status' => 'DRAFT', 'reference' => 'DEDOSAN-KEG-'.$id, 'amount' => 75000]);
        $this->assertSame(2, DB::table('billing_event_subjects')->where('billing_event_id', $event)->distinct()->count('kk_id'));
        $this->assertDatabaseCount('bills', 0);
        $this->rejected(fn () => $candidates->decide($this->kesinoman, $id, $a, ['decision' => 'RESET']), 409);
        // Phase 5 review and issue apply unchanged.
        app(FinanceManager::class)->review($this->kelian, $event, ['action' => 'approve', 'review_note' => 'Sesuai presensi terverifikasi']);
        app(FinanceManager::class)->issue($this->kesinoman, $event);
        $this->assertSame([$this->p['Made'], $this->p['Wayan']], DB::table('bills')->where('billing_event_id', $event)->orderBy('subject_krama_id')->pluck('subject_krama_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_minutes_decisions_attachments_finalize_and_pages(): void
    {
        $id = $this->activity([], 'Paruman September');
        $minutes = app(MinutesManager::class);
        $minutes->save($this->penyarikan, $id, ['agenda' => 'Persiapan piodalan', 'minutes' => 'Dibuka oleh Kelian.', 'decisions' => "Iuran piodalan Rp50.000\n\nNgayah dimulai 07.00"]);
        $this->assertSame(2, DB::table('activity_decisions')->where('activity_id', $id)->count());
        $this->invalid(fn () => $minutes->save($this->penyarikan, $id, ['minutes' => 'Versi lama', 'version' => '99']), 'minutes');
        Storage::fake('local');
        $this->assertSame(2, $minutes->attach($this->penyarikan, $id, [UploadedFile::fake()->create('notulen.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('foto-rapat.jpg', 400, 300)]));
        $this->assertSame(['PRIVATE'], DB::table('media_files')->whereIn('id', DB::table('activity_attachments')->where('activity_id', $id)->pluck('media_id'))->distinct()->pluck('visibility')->all());
        $this->invalid(fn () => $minutes->attach($this->penyarikan, $id, []), 'files');
        $this->tenantLogin($this->penyarikan)->get($this->host.'/admin/activities/'.$id.'/minutes')->assertOk()->assertSee('Pilih foto atau PDF')->assertSee('foto-rapat.jpg')->assertDontSee('Pilih dari pustaka media');
        $this->context();
        $this->rejected(fn () => $minutes->save($this->petengen, $id, ['minutes' => 'Bukan penulis', 'version' => '1']), 403);
        $minutes->finalize($this->penyarikan, $id);
        $this->rejected(fn () => $minutes->save($this->penyarikan, $id, ['minutes' => 'Ubah setelah final', 'version' => '1']), 409);

        $this->activities->addTarget($this->admin, $id, ['target_type' => 'ALL_KRAMA']);
        $this->activities->generate($this->admin, $id);
        $this->started($id);
        $this->activities->markRemainingAbsent($this->penyarikan, $id);
        $this->activities->verify($this->penyarikan, $id);
        foreach (['', '/'.$id, '/'.$id.'/attendance', '/'.$id.'/dedosan', '/'.$id.'/minutes'] as $path) {
            $this->tenantLogin($this->penyarikan)->get($this->host.'/admin/activities'.$path)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        }
        $this->tenantLogin($this->kesinoman)->get($this->host.'/admin/activities/'.$id.'/dedosan')->assertOk()->assertSee('Buat event dedosan');
        $this->tenantLogin($this->penyarikan)->get($this->host.'/admin/activities/'.$id.'/minutes')->assertOk()->assertSee('Iuran piodalan Rp50.000')->assertDontSee('Simpan notulen');
    }
}

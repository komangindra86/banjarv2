<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Krama\ChangeManager;
use App\Modules\Krama\KramaAccess;
use App\Modules\Krama\KramaManager;
use App\Platform\Identity\MembershipManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class KramaTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private int $a;

    private int $b;

    private string $host = 'http://alpha.localhost';

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
        config(['platform.host' => 'admin.banjar.localhost', 'platform.tenant_base_domain' => 'localhost']);
        $this->seed(RoleSeeder::class);
        $this->super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $this->super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        foreach (['a' => 'alpha', 'b' => 'beta'] as $key => $slug) {
            $this->$key = app(TenantManager::class)->create($this->super, ['name' => 'Banjar '.$slug, 'slug' => $slug, 'admin_name' => 'Admin '.$slug, 'admin_email' => $slug.'@example.test', 'admin_password' => 'Test-Password-12345', 'admin_password_confirmation' => 'Test-Password-12345']);
            DB::table('banjar_modules')->where('banjar_id', $this->$key)->where('code', 'KRAMA')->update(['status' => 'ACTIVE']);
        }
        $this->admin = User::where('email', 'alpha@example.test')->firstOrFail();
        $this->context();
    }

    private function context(?int $id = null): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($id ?? $this->a));
    }

    private function login(?User $user = null): void
    {
        $user ??= $this->admin;
        $this->actingAs($user)->withSession(['auth_host' => 'alpha.localhost', 'auth_context' => (string) $this->a]);
    }

    private function role(string $role, ?int $unit = null, bool $descendants = false): User
    {
        $user = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->a, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->a, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $role)->value('id'), 'scope_type' => $unit ? 'ORGANIZATIONAL_UNIT' : 'BANJAR', 'unit_id' => $unit, 'include_descendants' => $descendants]);

        return $user;
    }

    private function unit(string $name, ?int $parent = null): int
    {
        $this->context();

        return app(KramaManager::class)->unit($this->admin, null, ['name' => $name, 'type' => 'TEMPEKAN', 'parent_id' => $parent, 'is_active' => true]);
    }

    private function household(string $code = 'KK-001', array $units = []): int
    {
        $this->context();

        return app(KramaManager::class)->household($this->admin, null, ['code' => $code, 'status' => 'ACTIVE', 'unit_ids' => $units]);
    }

    private function person(int $household, array $extra = []): int
    {
        $this->context();

        return app(KramaManager::class)->person($this->admin, null, $extra + ['name' => 'Made Uji', 'status' => 'ACTIVE', 'household_id' => $household]);
    }

    public function test_household_create_accepts_optional_head_name_in_one_step(): void
    {
        $unit = $this->unit('Tempekan Kangin');
        $this->login();
        $this->post($this->host.'/admin/krama/households', ['code' => 'KK-COMBO', 'status' => 'ACTIVE', 'unit_ids' => [$unit], 'head_name' => 'I Made Suarta'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $household = DB::table('households')->where('code', 'KK-COMBO')->first();
        $this->assertNotNull($household->head_krama_id);
        $this->assertDatabaseHas('krama', ['id' => $household->head_krama_id, 'household_id' => $household->id, 'name' => 'I Made Suarta', 'status' => 'ACTIVE']);
        // Leaving head_name blank keeps the existing "household only" behavior.
        $this->post($this->host.'/admin/krama/households', ['code' => 'KK-EMPTY', 'status' => 'ACTIVE', 'unit_ids' => [$unit]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('households', ['code' => 'KK-EMPTY', 'head_krama_id' => null]);
        // head_name is ignored on update — it must not resurrect/rename a member via the household form.
        $this->patch($this->host.'/admin/krama/households/'.$household->id, ['version' => $household->version, 'code' => 'KK-COMBO', 'status' => 'ACTIVE', 'unit_ids' => [$unit], 'head_name' => 'Should Be Ignored'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('krama', 1);
    }

    public function test_person_create_locks_household_from_query_context_and_redirects_there(): void
    {
        $unit = $this->unit('Tempekan Kangin');
        $hh = $this->household('KK-201', [$unit]);
        $other = $this->household('KK-202', [$unit]);
        $this->login();
        $this->get($this->host.'/admin/krama/people/create?household='.$hh)->assertOk()->assertSee('KK-201', false)->assertDontSee('KK-202', false);
        $response = $this->post($this->host.'/admin/krama/people', ['name' => 'Anggota Baru', 'status' => 'ACTIVE', 'household_id' => $hh]);
        $response->assertRedirect($this->host.'/admin/krama/households/'.$hh)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('krama', ['household_id' => $hh, 'name' => 'Anggota Baru']);
        // Without household context the free picker (listing every in-scope KK) is still available.
        $this->get($this->host.'/admin/krama/people/create')->assertOk()->assertSee('KK-201', false)->assertSee('KK-202', false);
    }

    public function test_relationship_is_set_per_member_and_head_always_overrides_display(): void
    {
        $unit = $this->unit('Tempekan Kangin');
        $hh = $this->household('KK-301', [$unit]);
        $wife = $this->person($hh, ['name' => 'Ni Wayan Istri', 'relationship' => 'ISTRI_SUAMI']);
        $this->assertDatabaseHas('krama', ['id' => $wife, 'relationship' => 'ISTRI_SUAMI']);
        $this->login();
        $this->get($this->host.'/admin/krama/households/'.$hh)->assertOk()->assertSee('Istri/Suami', false);
        // Make her head of household: display must show "Kepala Keluarga" even though relationship is still stored underneath.
        $household = DB::table('households')->find($hh);
        $this->patch($this->host.'/admin/krama/households/'.$hh, ['version' => $household->version, 'code' => 'KK-301', 'status' => 'ACTIVE', 'head_krama_id' => $wife, 'unit_ids' => [$unit]])->assertSessionHasNoErrors();
        $response = $this->get($this->host.'/admin/krama/households/'.$hh)->assertOk();
        $response->assertSee('Kepala Keluarga', false)->assertDontSee('Istri/Suami', false);
        $this->get($this->host.'/admin/krama/people/'.$wife)->assertOk()->assertSee('Kepala Keluarga', false);
        // The relationship select is not offered for the current head — editing other fields must not require/clear it.
        $this->patch($this->host.'/admin/krama/people/'.$wife, ['version' => 1, 'name' => 'Ni Wayan Istri', 'status' => 'ACTIVE', 'household_id' => $hh, 'phone' => '081234567890'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('krama', ['id' => $wife, 'relationship' => 'ISTRI_SUAMI']);
    }

    public function test_relationship_change_request_supports_setting_and_clearing_without_error(): void
    {
        $hh = $this->household();
        $person = $this->person($hh, ['relationship' => 'ANAK']);
        $verifier = $this->role('KESINOMAN');
        $this->login();
        $this->post($this->host.'/admin/krama/people/'.$person.'/change', ['field' => 'relationship', 'value' => 'ORANG_TUA', 'reason' => 'Koreksi data', 'version' => 1])->assertSessionHasNoErrors();
        $id = DB::table('krama_change_requests')->value('id');
        $this->login($verifier);
        $this->post($this->host.'/admin/krama/changes/'.$id.'/review', ['action' => 'verify'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('krama', ['id' => $person, 'relationship' => 'ORANG_TUA']);
        // Clearing it back to blank must not crash on the ENUM column (empty string vs null).
        $this->login();
        $this->post($this->host.'/admin/krama/people/'.$person.'/change', ['field' => 'relationship', 'value' => '', 'reason' => 'Hapus data', 'version' => 2])->assertSessionHasNoErrors();
        $id2 = DB::table('krama_change_requests')->orderByDesc('id')->value('id');
        $this->login($verifier);
        $this->post($this->host.'/admin/krama/changes/'.$id2.'/review', ['action' => 'verify'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('krama', ['id' => $person, 'relationship' => null]);
    }

    public function test_household_member_head_search_and_private_views(): void
    {
        $unit = $this->unit('Tempekan Kangin');
        $hh = $this->household('KK-101', [$unit]);
        $person = $this->person($hh, ['nik' => '1234567890123456']);
        $this->login();
        $this->patch($this->host.'/admin/krama/households/'.$hh, ['version' => 1, 'code' => 'KK-101', 'status' => 'ACTIVE', 'head_krama_id' => $person, 'family_number' => '9876543210987654', 'unit_ids' => [$unit]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('households', ['id' => $hh, 'head_krama_id' => $person]);
        foreach (['', '/households/create', '/households/'.$hh, '/people/create', '/people/'.$person, '/units', '/changes', '/people/'.$person.'/change'] as $path) {
            $this->get($this->host.'/admin/krama'.$path)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertDontSee('1234567890123456')->assertDontSee('9876543210987654');
        }
        $this->get($this->host.'/admin')->assertOk()->assertSee('Keluarga & krama', false);
        $this->get($this->host.'/admin/krama?q=Made')->assertSee('KK-101');
        $this->get($this->host.'/admin/krama?tab=people&q=Made')->assertSee('Made Uji');
        $this->get($this->host.'/admin/krama?q=Missing')->assertDontSee('KK-101');
        $this->assertNotSame('1234567890123456', DB::table('krama')->find($person)->nik);
        $this->assertSame('1234567890123456', Crypt::decryptString(DB::table('krama')->find($person)->nik));
        $this->assertStringNotContainsString('1234567890123456', DB::table('audit_logs')->get()->toJson());
        $this->get($this->host.'/')->assertDontSee('Made Uji');
    }

    public function test_head_must_belong_to_household_when_set_via_household_form(): void
    {
        $a = $this->household();
        $b = $this->household('KK-002');
        $person = $this->person($a);
        $this->login();
        $this->patch($this->host.'/admin/krama/households/'.$b, ['version' => 1, 'code' => 'KK-002', 'status' => 'ACTIVE', 'head_krama_id' => $person])->assertSessionHasErrors('head_krama_id');
        $this->patch($this->host.'/admin/krama/households/'.$a, ['version' => 1, 'code' => 'KK-001', 'status' => 'ACTIVE', 'head_krama_id' => $person])->assertSessionHasNoErrors();
        // Constraint tingkat basis data tetap berlaku: tak bisa diakali dengan menulis langsung ke tabel.
        $this->expectException(QueryException::class);
        DB::table('households')->where('id', $b)->update(['head_krama_id' => $person]);
    }

    public function test_head_moving_household_auto_clears_the_vacated_household_head(): void
    {
        $a = $this->household();
        $b = $this->household('KK-002');
        $person = $this->person($a);
        $this->login();
        $this->patch($this->host.'/admin/krama/households/'.$a, ['version' => 1, 'code' => 'KK-001', 'status' => 'ACTIVE', 'head_krama_id' => $person])->assertSessionHasNoErrors();
        // Kepala keluarga pindah KK: KK asal otomatis kehilangan kepala keluarganya, tanpa perlu
        // mengosongkannya secara manual dulu di halaman KK (lihat KramaManager::person()).
        $this->patch($this->host.'/admin/krama/people/'.$person, ['version' => 1, 'name' => 'Made Uji', 'status' => 'ACTIVE', 'household_id' => $b])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'KK-001') && str_contains($m, 'tetapkan penggantinya'));
        $this->assertDatabaseHas('households', ['id' => $a, 'head_krama_id' => null]);
    }

    public function test_head_becoming_deceased_auto_clears_household_head(): void
    {
        $a = $this->household();
        $person = $this->person($a);
        $this->login();
        $this->patch($this->host.'/admin/krama/households/'.$a, ['version' => 1, 'code' => 'KK-001', 'status' => 'ACTIVE', 'head_krama_id' => $person])->assertSessionHasNoErrors();
        // Kepala keluarga meninggal: KK-nya otomatis kehilangan kepala keluarga (jadi dikecualikan dari
        // dedosan/iuran/patus sampai penggantinya ditetapkan), Kesinoman tidak perlu bolak-balik ke KK dulu.
        $this->patch($this->host.'/admin/krama/people/'.$person, ['version' => 1, 'name' => 'Made Uji', 'status' => 'DECEASED', 'household_id' => $a])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'KK-001') && str_contains($m, 'tetapkan penggantinya'));
        $this->assertDatabaseHas('households', ['id' => $a, 'head_krama_id' => null]);
        $this->assertDatabaseHas('krama', ['id' => $person, 'status' => 'DECEASED']);
    }

    public function test_cross_tenant_ids_and_relations_are_rejected(): void
    {
        $foreign = DB::table('households')->insertGetId(['banjar_id' => $this->b, 'code' => 'Secret Beta']);
        $person = DB::table('krama')->insertGetId(['banjar_id' => $this->b, 'household_id' => $foreign, 'name' => 'Secret Person']);
        $unit = DB::table('organizational_units')->insertGetId(['banjar_id' => $this->b, 'name' => 'Secret Unit', 'type' => 'REGU']);
        $this->login();
        foreach (['/households/'.$foreign, '/people/'.$person, '/people/'.$person.'/change'] as $path) {
            $this->get($this->host.'/admin/krama'.$path)->assertNotFound();
        }
        $this->get($this->host.'/admin/krama')->assertDontSee('Secret Beta');
        $this->post($this->host.'/admin/krama/people', ['household_id' => $foreign, 'name' => 'Bad', 'status' => 'ACTIVE'])->assertNotFound();
        $this->post($this->host.'/admin/krama/households', ['code' => 'Bad', 'status' => 'ACTIVE', 'unit_ids' => [$unit]])->assertSessionHasErrors('unit_ids');
        $this->post($this->host.'/admin/krama/units', ['name' => 'Bad', 'type' => 'REGU', 'is_active' => true, 'parent_id' => $unit])->assertSessionHasErrors('parent_id');
        $this->expectException(QueryException::class);
        DB::table('krama')->insert(['banjar_id' => $this->a, 'household_id' => $foreign, 'name' => 'Bad FK']);
    }

    public function test_scope_filters_lists_objects_search_and_descendants(): void
    {
        $parent = $this->unit('Kangin');
        $child = $this->unit('Regu 1', $parent);
        $outside = $this->unit('Kauh');
        $a = $this->household('KK-Allowed', [$parent]);
        $b = $this->household('KK-Child', [$child]);
        $c = $this->household('KK-Outside', [$outside]);
        $person = $this->person($c, ['name' => 'Person Outside']);
        $operator = $this->role('KESINOMAN', $parent);
        $this->login($operator);
        $this->get($this->host.'/admin/krama')->assertSee('KK-Allowed')->assertDontSee('KK-Child')->assertDontSee('KK-Outside');
        $this->get($this->host.'/admin/krama/households/'.$c)->assertNotFound();
        $this->get($this->host.'/admin/krama/people/'.$person)->assertNotFound();
        $this->patch($this->host.'/admin/krama/households/'.$c, ['version' => 1, 'code' => 'Hijacked', 'status' => 'ACTIVE'])->assertNotFound();
        $this->post($this->host.'/admin/krama/households', ['code' => 'Bad', 'status' => 'ACTIVE', 'unit_ids' => [$outside]])->assertSessionHasErrors('unit_ids');
        $this->post($this->host.'/admin/krama/households', ['code' => 'Unscoped', 'status' => 'ACTIVE'])->assertSessionHasErrors('unit_ids');
        $this->get($this->host.'/admin/krama/units')->assertForbidden();
        $this->get($this->host.'/admin/krama?unit='.$outside)->assertNotFound();
        DB::table('tenant_role_assignments')->where('unit_id', $parent)->update(['include_descendants' => true]);
        $this->get($this->host.'/admin/krama')->assertSee('KK-Child')->assertDontSee('KK-Outside');
        $this->context();
        $this->assertCount(2, app(KramaAccess::class)->households($operator)->get());
    }

    public function test_individual_unit_scope_does_not_expose_whole_household(): void
    {
        $unit = $this->unit('Maksan');
        $hh = $this->household();
        $person = $this->person($hh, ['unit_ids' => [$unit]]);
        $other = $this->person($hh, ['name' => 'Private Sibling']);
        $operator = $this->role('KESINOMAN', $unit);
        $this->login($operator);
        $this->get($this->host.'/admin/krama/people/'.$person)->assertOk()->assertDontSee('Private Sibling');
        $this->get($this->host.'/admin/krama/households/'.$hh)->assertNotFound();
        $this->get($this->host.'/admin/krama/people/'.$other)->assertNotFound();
        $this->patch($this->host.'/admin/krama/people/'.$person, ['version' => 1, 'name' => 'Updated', 'status' => 'ACTIVE', 'household_id' => $hh, 'unit_ids' => [$unit]])->assertSessionHasNoErrors();
    }

    public function test_units_reject_cycles_and_archiving_in_use(): void
    {
        $parent = $this->unit('Parent');
        $child = $this->unit('Child', $parent);
        $this->login();
        $this->patch($this->host.'/admin/krama/units/'.$parent, ['name' => 'Parent', 'type' => 'TEMPEKAN', 'is_active' => 1, 'parent_id' => $child])->assertSessionHasErrors('parent_id');
        $this->patch($this->host.'/admin/krama/units/'.$parent, ['name' => 'Parent', 'type' => 'TEMPEKAN', 'is_active' => 0])->assertSessionHasErrors('is_active');
        $this->patch($this->host.'/admin/krama/units/'.$child, ['name' => 'Child', 'type' => 'REGU', 'is_active' => 0, 'parent_id' => $parent])->assertSessionHasNoErrors();
    }

    public function test_module_role_and_direct_service_gates(): void
    {
        $hh = $this->household();
        $this->login($this->role('PETENGEN'));
        $this->get($this->host.'/admin/krama')->assertForbidden();
        $this->post($this->host.'/admin/krama/households', ['code' => 'No', 'status' => 'ACTIVE'])->assertForbidden();
        $this->login();
        DB::table('banjar_modules')->where('banjar_id', $this->a)->where('code', 'KRAMA')->update(['status' => 'INACTIVE']);
        $this->get($this->host.'/admin')->assertOk()->assertDontSee('href="/admin/krama"', false);
        $this->get($this->host.'/admin/krama/households/'.$hh)->assertForbidden();
        $this->context();
        $this->expectException(HttpException::class);
        app(KramaManager::class)->household($this->admin, null, ['code' => 'No', 'status' => 'ACTIVE']);
    }

    public function test_self_service_ownership_and_verification_without_approval(): void
    {
        $self = $this->role('KRAMA');
        $hh = $this->household();
        $person = $this->person($hh, ['user_id' => $self->id]);
        $other = $this->person($hh);
        $this->login($self);
        $this->get($this->host.'/admin/krama/mine')->assertOk()->assertSee('Made Uji');
        $this->get($this->host.'/admin/krama')->assertForbidden();
        $this->post($this->host.'/admin/krama/people/'.$other.'/change', ['field' => 'name', 'value' => 'Wrong', 'reason' => 'Test', 'version' => 1])->assertNotFound();
        $this->post($this->host.'/admin/krama/people/'.$person.'/change', ['field' => 'name', 'value' => 'Made Baru', 'reason' => 'Koreksi nama', 'version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $id = DB::table('krama_change_requests')->value('id');
        $this->assertDatabaseHas('krama', ['id' => $person, 'name' => 'Made Uji']);
        $this->get($this->host.'/admin/krama/changes/'.$id)->assertOk()->assertSee('Made Baru');
        $this->post($this->host.'/admin/krama/changes/'.$id.'/review', ['action' => 'verify'])->assertForbidden();
        $this->login($this->role('KESINOMAN'));
        $this->post($this->host.'/admin/krama/changes/'.$id.'/review', ['action' => 'verify'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('krama', ['id' => $person, 'name' => 'Made Baru', 'version' => 2]);
        $this->assertDatabaseHas('krama_change_requests', ['id' => $id, 'status' => 'APPLIED']);
        $this->post($this->host.'/admin/krama/changes/'.$id.'/review', ['action' => 'verify'])->assertSessionHasErrors('action');
    }

    public function test_approval_snapshot_separation_and_conflicting_master(): void
    {
        $hh = $this->household();
        $person = $this->person($hh);
        $manager = app(ChangeManager::class);
        $kesinoman = $this->role('KESINOMAN');
        $kelian = $this->role('KELIAN');
        app(KramaManager::class)->policy($this->admin, ['krama_changes_require_approval' => true]);
        $id = $manager->submit($this->admin, $person, ['version' => 1, 'field' => 'phone', 'value' => '08123456789', 'reason' => 'Nomor baru']);
        app(KramaManager::class)->policy($this->admin, ['krama_changes_require_approval' => false]);
        try {
            $manager->review($this->admin, $id, ['action' => 'verify']);
            $this->fail('Self review allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('VERIFIED', $manager->review($kesinoman, $id, ['action' => 'verify']));
        $this->assertDatabaseHas('krama', ['id' => $person, 'phone' => null]);
        app(KramaManager::class)->person($this->admin, $person, ['version' => 1, 'name' => 'Concurrent correction', 'status' => 'ACTIVE', 'household_id' => $hh]);
        $this->assertSame('CONFLICT', $manager->review($kelian, $id, ['action' => 'approve']));
        $this->assertDatabaseHas('krama', ['id' => $person, 'name' => 'Concurrent correction', 'phone' => null]);
    }

    public function test_kelian_approval_and_rejection_preserve_history(): void
    {
        $hh = $this->household();
        $person = $this->person($hh);
        $verifier = $this->role('KESINOMAN');
        $approver = $this->role('KELIAN');
        $manager = app(ChangeManager::class);
        app(KramaManager::class)->policy($this->admin, ['krama_changes_require_approval' => true]);
        $id = $manager->submit($this->admin, $person, ['version' => 1, 'field' => 'nik', 'value' => '1234567890123456', 'reason' => 'Koreksi']);
        $this->assertSame('VERIFIED', $manager->review($verifier, $id, ['action' => 'verify']));
        $this->assertSame('APPLIED', $manager->review($approver, $id, ['action' => 'approve']));
        $this->assertDatabaseHas('krama_change_requests', ['id' => $id, 'requester_id' => $this->admin->id, 'verifier_id' => $verifier->id, 'approver_id' => $approver->id]);
        $this->assertSame('1234567890123456', Crypt::decryptString(DB::table('krama')->find($person)->nik));
        $this->assertStringNotContainsString('1234567890123456', DB::table('krama_change_items')->get()->toJson());
        $this->login($approver);
        $this->get($this->host.'/admin/krama/changes/'.$id)->assertOk()->assertDontSee('1234567890123456');
        $this->context();
        $second = $manager->submit($this->admin, $person, ['version' => 2, 'field' => 'name', 'value' => 'Rejected name', 'reason' => 'Koreksi']);
        $this->assertSame('REJECTED', $manager->review($verifier, $second, ['action' => 'reject', 'review_note' => 'Data tidak sesuai']));
        $this->assertDatabaseHas('krama', ['id' => $person, 'name' => 'Made Uji']);
    }

    public function test_stale_forms_and_change_allowlist(): void
    {
        $hh = $this->household();
        $person = $this->person($hh);
        $this->login();
        $this->patch($this->host.'/admin/krama/people/'.$person, ['version' => 0, 'name' => 'Bad', 'status' => 'ACTIVE', 'household_id' => $hh])->assertSessionHasErrors('version');
        $this->post($this->host.'/admin/krama/people/'.$person.'/change', ['version' => 1, 'field' => 'user_id', 'value' => $this->admin->id, 'reason' => 'Bad'])->assertSessionHasErrors('field');
        $this->post($this->host.'/admin/krama/people/'.$person.'/change', ['version' => 1, 'field' => 'nik', 'value' => '12345678901234567', 'reason' => 'Bad'])->assertSessionHasErrors('nik')->assertSessionMissing('_old_input.value');
    }

    public function test_platform_scope_assignment_validates_tenant_and_preserves_scope_on_updates(): void
    {
        $unit = $this->unit('Kangin');
        $operator = $this->role('KESINOMAN');
        $member = DB::table('banjar_user_memberships')->where('user_id', $operator->id)->value('id');
        $role = DB::table('roles')->where('code', 'KESINOMAN')->value('id');
        app(MembershipManager::class)->update($this->super, $this->a, $member, ['role_id' => $role, 'status' => 'ACTIVE', 'scope_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $unit, 'include_descendants' => true]);
        app(MembershipManager::class)->update($this->super, $this->a, $member, ['role_id' => $role, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('tenant_role_assignments', ['membership_id' => $member, 'unit_id' => $unit, 'include_descendants' => true]);
        $foreign = DB::table('organizational_units')->insertGetId(['banjar_id' => $this->b, 'name' => 'Beta', 'type' => 'REGU']);
        $this->expectException(ValidationException::class);
        app(MembershipManager::class)->update($this->super, $this->a, $member, ['role_id' => $role, 'status' => 'ACTIVE', 'scope_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $foreign]);
    }

    public function test_change_review_respects_unit_scope_and_requester_ownership(): void
    {
        $unit = $this->unit('Kangin');
        $outside = $this->unit('Kauh');
        $hh = $this->household('KK-Outside', [$outside]);
        $person = $this->person($hh);
        $id = app(ChangeManager::class)->submit($this->admin, $person, ['version' => 1, 'field' => 'name', 'value' => 'Private change', 'reason' => 'Private reason']);
        $this->login($this->role('KESINOMAN', $unit));
        $this->get($this->host.'/admin/krama/changes')->assertOk()->assertDontSee('Pengajuan #'.$id);
        $this->get($this->host.'/admin/krama/changes/'.$id)->assertNotFound();
        $this->post($this->host.'/admin/krama/changes/'.$id.'/review', ['action' => 'verify'])->assertNotFound();
        $this->login($this->role('KRAMA'));
        $this->get($this->host.'/admin/krama/changes/'.$id)->assertNotFound();
        $this->assertDatabaseHas('krama_change_requests', ['id' => $id, 'status' => 'SUBMITTED']);
    }

    public function test_membership_move_preserves_identity_and_scope_memberships(): void
    {
        $unit = $this->unit('Kangin');
        $otherUnit = $this->unit('Maksan');
        $hh = $this->household('KK-Old', [$unit, $otherUnit]);
        $destination = $this->household('KK-New', [$unit]);
        $person = $this->person($hh, ['nik' => '1234567890123456']);
        $this->login($this->role('KESINOMAN', $unit));
        $this->get($this->host.'/admin/krama?tab=people&unit='.$unit)->assertSee('Made Uji');
        $this->patch($this->host.'/admin/krama/households/'.$hh, ['version' => 1, 'code' => 'KK-Old', 'status' => 'ACTIVE', 'unit_ids' => [$unit]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('household_unit_memberships', ['household_id' => $hh, 'unit_id' => $otherUnit]);
        $this->patch($this->host.'/admin/krama/people/'.$person, ['version' => 1, 'name' => 'Made Uji', 'status' => 'ACTIVE', 'household_id' => $destination, 'nik' => ''])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('krama', ['id' => $person, 'household_id' => $destination, 'version' => 2]);
        $this->assertSame('1234567890123456', Crypt::decryptString(DB::table('krama')->find($person)->nik));
    }

    public function test_request_status_change_cannot_invalidate_household_head(): void
    {
        $hh = $this->household();
        $person = $this->person($hh);
        app(KramaManager::class)->household($this->admin, $hh, ['version' => 1, 'code' => 'KK-001', 'status' => 'ACTIVE', 'head_krama_id' => $person]);
        $id = app(ChangeManager::class)->submit($this->admin, $person, ['version' => 1, 'field' => 'status', 'value' => 'DECEASED', 'reason' => 'Perubahan status']);
        $this->login($this->role('KESINOMAN'));
        $this->post($this->host.'/admin/krama/changes/'.$id.'/review', ['action' => 'verify'])->assertSessionHasErrors('action');
        $this->assertDatabaseHas('krama', ['id' => $person, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('krama_change_requests', ['id' => $id, 'status' => 'SUBMITTED']);
    }
}

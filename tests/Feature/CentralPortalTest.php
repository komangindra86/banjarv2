<?php

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

// §53: portal krama pusat — satu host untuk semua Banjar, Banjar ditentukan dari akun warga.
class CentralPortalTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $tenant;

    private User $super;

    private User $krama;

    private string $portal = 'http://portal.banjar.localhost';

    private string $password = 'Portal-Test-123456';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Central portal tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.portal_host' => 'portal.banjar.localhost']);
        $this->fixture = FinanceFixture::create();
        $this->tenant = $this->fixture['tenant'];
        $this->super = User::whereIn('id', DB::table('platform_role_assignments')->select('user_id'))->firstOrFail();
        $this->krama = User::factory()->create(['email' => 'warga@example.test', 'password' => $this->password]);
        $this->member($this->krama, $this->tenant, 'KRAMA');
        DB::table('krama')->where('id', $this->fixture['people'][0])->update(['user_id' => $this->krama->id]);
        $this->module($this->tenant, 'PORTAL');
        app(CurrentBanjar::class)->clear();
    }

    private function member(User $user, int $tenant, string $role): void
    {
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $role)->value('id'), 'scope_type' => 'BANJAR']);
    }

    private function module(int $tenant, string $code, string $status = 'ACTIVE'): void
    {
        DB::table('banjar_modules')->where('banjar_id', $tenant)->where('code', $code)->update(['status' => $status]);
    }

    private function otherBanjar(string $slug, string $name): int
    {
        $id = app(TenantManager::class)->create($this->super, ['name' => $name, 'slug' => $slug, 'admin_name' => 'Admin '.$name, 'admin_email' => $slug.'@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        foreach (['KRAMA', 'FINANCE', 'PAYMENT', 'PORTAL'] as $code) {
            app(TenantManager::class)->module($this->super, $id, $code, ['status' => 'ACTIVE']);
        }

        return $id;
    }

    private function login(string $email = 'warga@example.test')
    {
        return $this->post($this->portal.'/login', ['email' => $email, 'password' => $this->password]);
    }

    public function test_warga_with_one_banjar_lands_on_own_portal(): void
    {
        $this->get($this->portal.'/')->assertRedirect('/portal/beranda');
        $this->get($this->portal.'/portal/beranda')->assertRedirect('/login');
        $this->get($this->portal.'/login')->assertOk()->assertSee('Portal Krama')->assertSee('KRAMA BANJAR');

        $this->login()->assertRedirect('/portal/beranda');
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertSee('Rahajeng, Krama Uji 1')->assertSee('Banjar Uji Keuangan')
            ->assertSee('Data saya')->assertDontSee('Ganti banjar')->assertDontSee('href="/admin"', false);
        $this->assertDatabaseHas('audit_logs', ['banjar_id' => $this->tenant, 'actor_id' => $this->krama->id, 'action' => 'auth.login']);
    }

    public function test_login_returns_to_requested_portal_page(): void
    {
        $this->module($this->tenant, 'ADMINISTRATION');
        $this->get($this->portal.'/portal/surat')->assertRedirect('/login');
        $this->login()->assertRedirect('/portal/surat');
    }

    public function test_portal_requires_portal_module_and_krama_role(): void
    {
        $this->module($this->tenant, 'PORTAL', 'INACTIVE');
        $this->login()->assertSessionHasErrors('email');
        $this->assertGuest('web');

        // Pengurus tanpa peran Krama tidak bisa masuk portal pusat.
        $this->module($this->tenant, 'PORTAL');
        $this->post($this->portal.'/login', ['email' => 'admin-finance@example.test', 'password' => $this->fixture['password']])->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_admin_and_public_pages_are_closed_on_portal_host(): void
    {
        $this->login();
        foreach (['/admin', '/admin/finance', '/admin/krama', '/admin/payments', '/platform', '/berita'] as $path) {
            $this->get($this->portal.$path)->assertNotFound();
        }
        $this->get($this->portal.'/admin/krama/mine')->assertOk();
    }

    public function test_warga_in_two_banjars_chooses_and_switches(): void
    {
        $other = $this->otherBanjar('portal-dua', 'Banjar Kedua');
        $this->member($this->krama, $other, 'KRAMA');
        $closed = $this->otherBanjar('portal-tutup', 'Banjar Tertutup');
        $this->member($this->krama, $closed, 'KRAMA');
        $this->module($closed, 'PORTAL', 'INACTIVE');

        $this->login()->assertRedirect('/pilih-banjar');
        $this->get($this->portal.'/portal/beranda')->assertRedirect('/pilih-banjar');
        $this->get($this->portal.'/pilih-banjar')->assertOk()->assertSee('Banjar Kedua')->assertSee('Banjar Uji Keuangan')->assertDontSee('Banjar Tertutup');

        $this->post($this->portal.'/pilih-banjar', ['banjar_id' => $closed])->assertSessionHasErrors('banjar_id');
        $this->post($this->portal.'/pilih-banjar', ['banjar_id' => $other])->assertRedirect('/portal/beranda');
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertSee('Banjar Kedua')->assertSee('Akun belum terhubung')->assertSee('Ganti banjar');

        $this->post($this->portal.'/pilih-banjar', ['banjar_id' => $this->tenant])->assertRedirect('/portal/beranda');
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertSee('Rahajeng, Krama Uji 1')->assertSee('Banjar Uji Keuangan');
    }

    public function test_bills_stay_within_selected_banjar(): void
    {
        $this->module($this->tenant, 'PAYMENT');
        $other = $this->otherBanjar('portal-lain', 'Banjar Lain');
        $this->member($this->krama, $other, 'KRAMA');
        $household = DB::table('households')->insertGetId(['banjar_id' => $other, 'code' => 'KK-LAIN', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $person = DB::table('krama')->insertGetId(['banjar_id' => $other, 'household_id' => $household, 'name' => 'Warga Lain', 'status' => 'ACTIVE', 'user_id' => $this->krama->id, 'created_at' => now(), 'updated_at' => now()]);
        $type = DB::table('billing_types')->insertGetId(['banjar_id' => $other, 'name' => 'Iuran', 'kind' => 'IURAN', 'default_amount' => 999999, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $event = DB::table('billing_events')->insertGetId(['banjar_id' => $other, 'billing_type_id' => $type, 'kind' => 'IURAN', 'amount' => 999999, 'title' => 'Iuran lain', 'reference' => 'REF-LAIN', 'event_date' => now()->toDateString(), 'target' => 'ALL_ACTIVE', 'status' => 'ISSUED', 'requires_approval' => false, 'created_by' => $this->super->id, 'created_at' => now(), 'updated_at' => now(), 'issued_at' => now()]);
        DB::table('bills')->insert(['banjar_id' => $other, 'billing_event_id' => $event, 'kk_id' => $household, 'household_code' => 'KK-LAIN', 'kind' => 'IURAN', 'gross_amount' => 999999, 'status' => 'UNPAID', 'created_at' => now(), 'updated_at' => now()]);

        $this->login();
        $this->post($this->portal.'/pilih-banjar', ['banjar_id' => $this->tenant]);
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertDontSee('999.999');
        $this->get($this->portal.'/payments')->assertOk()->assertDontSee('999.999')->assertDontSee('Iuran lain');

        $this->post($this->portal.'/pilih-banjar', ['banjar_id' => $other]);
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertSee('Rahajeng, Warga Lain')->assertSee('Rp 999.999');
        $this->assertNotNull($person);
    }

    public function test_access_is_rechecked_when_portal_module_is_turned_off(): void
    {
        $this->login();
        $this->get($this->portal.'/portal/beranda')->assertOk();
        $this->module($this->tenant, 'PORTAL', 'INACTIVE');
        $this->get($this->portal.'/portal/beranda')->assertRedirect('/pilih-banjar');
        $this->get($this->portal.'/pilih-banjar')->assertOk()->assertSee('Portal Banjar belum aktif');
    }

    public function test_banjar_domain_login_is_unchanged_and_sessions_stay_separate(): void
    {
        $this->login();
        // Sesi portal tidak berlaku di domain Banjar (cookie host-only + auth_host).
        $this->get('http://financeqa.localhost/portal/beranda')->assertRedirect('/login');
        $this->get('http://financeqa.localhost/')->assertOk();
    }

    public function test_logout_from_portal(): void
    {
        $this->login();
        $this->post($this->portal.'/logout')->assertRedirect('/login');
        $this->assertGuest('web');
        $this->get($this->portal.'/portal/beranda')->assertRedirect('/login');
    }

    public function test_tenant_cannot_take_portal_host_as_domain(): void
    {
        config(['platform.portal_host' => 'portalx.localhost']);
        $this->expectException(ValidationException::class);
        $this->otherBanjar('portalx', 'Banjar Bentrok');
    }

    public function test_manifest_on_portal_is_generic(): void
    {
        $this->get($this->portal.'/manifest.webmanifest')->assertOk()->assertJson(['short_name' => 'Portal Krama', 'start_url' => '/portal/beranda']);
    }
}

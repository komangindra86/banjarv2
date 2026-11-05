<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Krama\AccountManager;
use App\Modules\Krama\KramaManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

// §23/§53: Admin Banjar mengaktifkan akun warga (login NIK, kata sandi awal), warga wajib mengganti kata sandi.
class KramaAccountTest extends TestCase
{
    use RefreshDatabase;

    private const NIK = '5103010101900001';

    private array $fixture;

    private int $tenant;

    private User $admin;

    private User $super;

    private int $person;

    private string $portal = 'http://portal.banjar.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Krama account tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.portal_host' => 'portal.banjar.localhost']);
        $this->fixture = FinanceFixture::create();
        $this->tenant = $this->fixture['tenant'];
        $this->admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $this->super = User::whereIn('id', DB::table('platform_role_assignments')->select('user_id'))->firstOrFail();
        $this->person = $this->fixture['people'][0];
        DB::table('krama')->where('id', $this->person)->update(['nik' => Crypt::encryptString(self::NIK)]);
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'PORTAL')->update(['status' => 'ACTIVE']);
        $this->context();
    }

    private function context(?int $tenant = null): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant ?? $this->tenant));
    }

    private function adminSession(): self
    {
        return $this->actingAs($this->admin, 'web')->withSession(['auth_host' => 'financeqa.localhost', 'auth_context' => (string) $this->tenant]);
    }

    private function warga(): User
    {
        return User::findOrFail(DB::table('krama')->where('id', $this->person)->value('user_id'));
    }

    public function test_admin_activates_account_with_one_click(): void
    {
        $this->adminSession()->get('http://financeqa.localhost/admin/krama/people/'.$this->person)->assertOk()->assertSee('Aktifkan akun warga')->assertSee('balisantih');
        $this->adminSession()->post('http://financeqa.localhost/admin/krama/people/'.$this->person.'/account')->assertRedirect()->assertSessionHas('success');

        $user = $this->warga();
        $this->assertNull($user->email);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertSame(AccountManager::nikHash(self::NIK), $user->nik_hash);
        $this->assertTrue(Hash::check('balisantih', $user->password));
        $this->assertDatabaseHas('banjar_user_memberships', ['banjar_id' => $this->tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('tenant_role_assignments', ['banjar_id' => $this->tenant, 'role_id' => DB::table('roles')->where('code', 'KRAMA')->value('id'), 'scope_type' => 'BANJAR']);
        $this->assertDatabaseHas('audit_logs', ['banjar_id' => $this->tenant, 'actor_id' => $this->admin->id, 'action' => 'krama.account_activated']);

        $this->context();
        $this->adminSession()->get('http://financeqa.localhost/admin/krama?tab=people')->assertOk()->assertSee('Akun portal');
        $this->adminSession()->get('http://financeqa.localhost/admin/krama/people/'.$this->person)->assertOk()->assertSee('Reset kata sandi ke awal')->assertDontSee('Aktifkan akun warga');
    }

    public function test_activation_requires_nik_active_status_and_admin_permission(): void
    {
        $manager = app(AccountManager::class);
        $kesinoman = User::findOrFail($this->fixture['users']['KESINOMAN']);
        try {
            $manager->activate($kesinoman, $this->person);
            $this->fail('Kesinoman tidak boleh mengaktifkan akun.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $noNik = $this->fixture['people'][1];
        $this->expectException(ValidationException::class);
        $manager->activate($this->admin, $noNik);
    }

    public function test_inactive_krama_cannot_get_account(): void
    {
        DB::table('krama')->where('id', $this->person)->update(['status' => 'MOVED']);
        $this->expectException(ValidationException::class);
        app(AccountManager::class)->activate($this->admin, $this->person);
    }

    public function test_warga_logs_in_with_nik_and_must_change_default_password(): void
    {
        app(AccountManager::class)->activate($this->admin, $this->person);
        app(CurrentBanjar::class)->clear();

        $this->post($this->portal.'/login', ['email' => self::NIK, 'password' => 'balisantih'])->assertRedirect('/portal/beranda');
        $this->get($this->portal.'/portal/beranda')->assertRedirect('/akun/kata-sandi');
        $this->get($this->portal.'/payments')->assertRedirect('/akun/kata-sandi');
        $this->get($this->portal.'/akun/kata-sandi')->assertOk()->assertSee('kata sandi awal');

        $this->post($this->portal.'/akun/kata-sandi', ['current_password' => 'balisantih', 'password' => 'balisantih', 'password_confirmation' => 'balisantih'])->assertSessionHasErrors('password');
        $this->post($this->portal.'/akun/kata-sandi', ['current_password' => 'salah123', 'password' => 'Sandi-Baru-2026', 'password_confirmation' => 'Sandi-Baru-2026'])->assertSessionHasErrors('current_password');
        $this->post($this->portal.'/akun/kata-sandi', ['current_password' => 'balisantih', 'password' => 'Sandi-Baru-2026', 'password_confirmation' => 'Sandi-Baru-2026'])->assertRedirect('/portal/beranda');
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertSee('Rahajeng, Krama Uji 1');
        $this->assertFalse((bool) $this->warga()->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->warga()->id, 'action' => 'auth.password_changed']);

        $this->post($this->portal.'/logout');
        $this->post($this->portal.'/login', ['email' => self::NIK, 'password' => 'balisantih'])->assertSessionHasErrors('email');
        $this->post($this->portal.'/login', ['email' => self::NIK, 'password' => 'Sandi-Baru-2026'])->assertRedirect('/portal/beranda');
    }

    public function test_nik_login_on_banjar_domain_and_not_on_platform(): void
    {
        app(AccountManager::class)->activate($this->admin, $this->person);
        app(CurrentBanjar::class)->clear();

        $this->post('http://financeqa.localhost/login', ['email' => self::NIK, 'password' => 'balisantih'])->assertRedirect('/admin');
        $this->get('http://financeqa.localhost/admin')->assertRedirect('/akun/kata-sandi');
        $this->post('http://admin.banjar.localhost/login', ['email' => self::NIK, 'password' => 'balisantih'])->assertSessionHasErrors('email');
    }

    public function test_same_nik_in_another_banjar_reuses_account(): void
    {
        app(AccountManager::class)->activate($this->admin, $this->person);
        DB::table('users')->where('id', $this->warga()->id)->update(['password' => Hash::make('Sandi-Saya-2026'), 'must_change_password' => false]);

        $other = app(TenantManager::class)->create($this->super, ['name' => 'Banjar Kedua', 'slug' => 'akun-dua', 'admin_name' => 'Admin Dua', 'admin_email' => 'akun-dua@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        foreach (['KRAMA', 'PORTAL'] as $code) {
            app(TenantManager::class)->module($this->super, $other, $code, ['status' => 'ACTIVE']);
        }
        $this->context($other);
        $otherAdmin = User::where('email', 'akun-dua@example.test')->firstOrFail();
        $kk = app(KramaManager::class)->household($otherAdmin, null, ['code' => 'KK-DUA', 'status' => 'ACTIVE']);
        $person = app(KramaManager::class)->person($otherAdmin, null, ['household_id' => $kk, 'name' => 'Krama Uji 1', 'status' => 'ACTIVE', 'nik' => self::NIK]);
        app(AccountManager::class)->activate($otherAdmin, $person);

        $this->assertSame($this->warga()->id, DB::table('krama')->where('id', $person)->value('user_id'));
        $this->assertSame(1, User::where('nik_hash', AccountManager::nikHash(self::NIK))->count());
        $this->assertTrue(Hash::check('Sandi-Saya-2026', $this->warga()->password));

        app(CurrentBanjar::class)->clear();
        $this->post($this->portal.'/login', ['email' => self::NIK, 'password' => 'Sandi-Saya-2026'])->assertRedirect('/pilih-banjar');

        // Admin Banjar kedua (atau pertama) tidak boleh mereset kata sandi / mengganti NIK login akun yang juga dipakai di
        // Banjar lain — cukup tahu NIK tidak boleh membuat Admin bisa mengambil alih akun warga Banjar lain.
        $this->context($other);
        try {
            app(AccountManager::class)->resetPassword($otherAdmin, $person);
            $this->fail('Reset akun lintas Banjar harus ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account', $e->errors());
        }
        $row = DB::table('krama')->find($person);
        try {
            app(KramaManager::class)->person($otherAdmin, $person, ['household_id' => $row->household_id, 'name' => $row->name, 'status' => 'ACTIVE', 'version' => $row->version, 'nik' => '5103010101900099']);
            $this->fail('Ganti NIK akun lintas Banjar harus ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nik', $e->errors());
        }
        $this->assertSame(AccountManager::nikHash(self::NIK), $this->warga()->nik_hash);
        $this->assertTrue(Hash::check('Sandi-Saya-2026', $this->warga()->password));
        $this->context();
        $this->expectException(ValidationException::class);
        app(AccountManager::class)->resetPassword($this->admin, $this->person);
    }

    public function test_demo_account_password_is_locked(): void
    {
        app(AccountManager::class)->activate($this->admin, $this->person);
        DB::table('users')->where('id', $this->warga()->id)->update(['password' => Hash::make('balisantih'), 'must_change_password' => false, 'password_locked' => true]);
        app(CurrentBanjar::class)->clear();
        $this->post($this->portal.'/login', ['email' => self::NIK, 'password' => 'balisantih'])->assertRedirect('/portal/beranda');
        $this->get($this->portal.'/portal/beranda')->assertOk();
        $this->get($this->portal.'/akun/kata-sandi')->assertOk()->assertSee('akun demo bersama')->assertDontSee('name="password"', false);
        $this->post($this->portal.'/akun/kata-sandi', ['current_password' => 'balisantih', 'password' => 'Sandi-Baru-2026', 'password_confirmation' => 'Sandi-Baru-2026'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('balisantih', $this->warga()->password));
        $this->context();
        $this->expectException(ValidationException::class);
        app(AccountManager::class)->resetPassword($this->admin, $this->person);
    }

    public function test_reset_password_only_for_warga_accounts(): void
    {
        $accounts = app(AccountManager::class);
        $accounts->activate($this->admin, $this->person);
        DB::table('users')->where('id', $this->warga()->id)->update(['password' => Hash::make('Sandi-Saya-2026'), 'must_change_password' => false]);

        $this->adminSession()->post('http://financeqa.localhost/admin/krama/people/'.$this->person.'/account/reset')->assertSessionHas('success');
        $this->assertTrue(Hash::check('balisantih', $this->warga()->password));
        $this->assertTrue((bool) $this->warga()->must_change_password);

        // Akun yang juga pengurus tidak boleh direset dari data krama.
        $this->context();
        $membership = DB::table('banjar_user_memberships')->where('user_id', $this->warga()->id)->value('id');
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->tenant, 'membership_id' => $membership, 'role_id' => DB::table('roles')->where('code', 'PETENGEN')->value('id'), 'scope_type' => 'BANJAR']);
        $this->expectException(ValidationException::class);
        $accounts->resetPassword($this->admin, $this->person);
    }

    public function test_pengurus_email_account_cannot_be_reset_from_krama(): void
    {
        $petengen = $this->fixture['users']['PETENGEN'];
        DB::table('krama')->where('id', $this->person)->update(['user_id' => $petengen]);
        $this->expectException(ValidationException::class);
        app(AccountManager::class)->resetPassword($this->admin, $this->person);
    }

    public function test_changing_nik_updates_login(): void
    {
        app(AccountManager::class)->activate($this->admin, $this->person);
        $row = DB::table('krama')->find($this->person);
        app(KramaManager::class)->person($this->admin, $this->person, ['household_id' => $row->household_id, 'name' => $row->name, 'status' => 'ACTIVE', 'version' => $row->version, 'nik' => '5103010101900002']);
        $this->assertSame(AccountManager::nikHash('5103010101900002'), $this->warga()->nik_hash);
    }
}

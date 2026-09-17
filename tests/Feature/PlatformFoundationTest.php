<?php

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Identity\MembershipManager;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use App\Platform\Tenancy\TenantRunner;
use App\Platform\Tenants\SettingsManager;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private int $a;

    private int $b;

    private string $password = 'Local-test-password-123';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Refusing to refresh anything except banjarv2_testing on MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.mysql.database') !== 'banjarv2_testing') {
            throw new \RuntimeException('Tests require dedicated banjarv2_testing database.');
        }
        $this->withoutVite();
        config(['platform.host' => 'admin.banjar.localhost', 'platform.tenant_base_domain' => 'banjar.localhost']);
        $this->seed(RoleSeeder::class);
        $this->super = User::factory()->create(['email' => 'super@example.test', 'password' => $this->password, 'status' => 'ACTIVE']);
        DB::table('platform_role_assignments')->insert(['user_id' => $this->super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        $this->a = $this->createTenant('alpha');
        $this->b = $this->createTenant('beta');
        $this->admin = User::where('email', 'alpha@example.test')->firstOrFail();
    }

    private function createTenant(string $slug): int
    {
        return app(TenantManager::class)->create($this->super, ['name' => 'Banjar '.$slug, 'slug' => $slug, 'admin_name' => 'Admin '.$slug, 'admin_email' => $slug.'@example.test', 'admin_password' => $this->password, 'admin_password_confirmation' => $this->password]);
    }

    public function test_platform_can_manage_standard_roles_but_cannot_remove_last_admin(): void
    {
        $manager = app(MembershipManager::class);
        $kelian = DB::table('roles')->where('code', 'KELIAN')->value('id');
        $manager->create($this->super, $this->a, ['name' => 'Kelian Alpha', 'email' => 'kelian@example.test', 'password' => $this->password, 'password_confirmation' => $this->password, 'role_id' => $kelian]);
        $member = DB::table('banjar_user_memberships')->where('user_id', User::where('email', 'kelian@example.test')->value('id'))->first();
        $manager->update($this->super, $this->a, $member->id, ['role_id' => $kelian, 'status' => 'INACTIVE']);
        $this->assertDatabaseHas('banjar_user_memberships', ['id' => $member->id, 'status' => 'INACTIVE']);
        $adminMember = DB::table('banjar_user_memberships')->where('user_id', $this->admin->id)->first();
        try {
            $manager->update($this->super, $this->a, $adminMember->id, ['role_id' => $kelian, 'status' => 'INACTIVE']);
            $this->fail('Last admin was removed');
        } catch (ValidationException) {
        }
        $this->assertDatabaseHas('banjar_user_memberships', ['id' => $adminMember->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('tenant_role_assignments', ['membership_id' => $adminMember->id, 'role_id' => DB::table('roles')->where('code', 'ADMIN_BANJAR')->value('id')]);
    }

    public function test_membership_route_rejects_cross_tenant_id_and_platform_role(): void
    {
        $this->post('http://admin.banjar.localhost/login', ['email' => $this->super->email, 'password' => $this->password])->assertRedirect('/platform');
        $this->get('http://admin.banjar.localhost/platform/banjars/'.$this->a.'/members')->assertOk()->assertSee('Admin alpha')->assertDontSee('Admin beta');
        $bMember = DB::table('banjar_user_memberships')->where('banjar_id', $this->b)->value('id');
        $this->patch('http://admin.banjar.localhost/platform/banjars/'.$this->a.'/members/'.$bMember, ['role_id' => DB::table('roles')->where('code', 'KELIAN')->value('id'), 'status' => 'ACTIVE'])->assertNotFound();
        $this->post('http://admin.banjar.localhost/platform/banjars/'.$this->a.'/members', ['name' => 'Bad', 'email' => 'bad@example.test', 'password' => $this->password, 'password_confirmation' => $this->password, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')])->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('users', ['email' => 'bad@example.test']);
    }

    public function test_csrf_rejects_untrusted_post(): void
    {
        $this->app->instance('env', 'production');
        $this->post('http://alpha.banjar.localhost/login', ['email' => $this->admin->email, 'password' => $this->password])->assertStatus(419);
    }

    public function test_onboarding_validation_does_not_flash_passwords_or_create_partial_data(): void
    {
        $this->post('http://admin.banjar.localhost/login', ['email' => $this->super->email, 'password' => $this->password])->assertRedirect('/platform');
        $this->post('http://admin.banjar.localhost/platform/banjars', ['name' => 'Invalid', 'slug' => 'admin', 'admin_name' => 'Admin', 'admin_email' => 'new@example.test', 'admin_password' => $this->password, 'admin_password_confirmation' => $this->password])->assertSessionHasErrors('slug');
        $this->assertNull(session()->getOldInput('admin_password'));
        $this->assertNull(session()->getOldInput('admin_password_confirmation'));
        $this->assertDatabaseCount('banjars', 2);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_worker_context_is_cleared_after_success_and_exception(): void
    {
        $runner = app(TenantRunner::class);
        $this->assertSame($this->a, $runner->run($this->a, fn () => app(CurrentBanjar::class)->id()));
        $this->assertNull(app(CurrentBanjar::class)->optional());
        try {
            $runner->run($this->b, fn () => throw new \RuntimeException('Job failed'));
        } catch (\RuntimeException) {
        }
        $this->assertNull(app(CurrentBanjar::class)->optional());
    }

    public function test_suspended_tenant_cannot_use_direct_service_with_stale_context(): void
    {
        $this->setTenant($this->a);
        DB::table('banjars')->where('id', $this->a)->update(['status' => 'SUSPENDED']);
        $this->expectException(HttpException::class);
        app(SettingsManager::class)->update($this->admin, 1, ['template_code' => 'template-01', 'accent_color' => '#123456']);
    }

    public function test_domain_is_unique_and_cannot_be_registered_for_two_tenants(): void
    {
        $this->expectException(QueryException::class);
        DB::table('tenant_domains')->insert(['banjar_id' => $this->b, 'domain' => 'alpha.banjar.localhost']);
    }

    public function test_database_rejects_basic_mode_for_other_modules(): void
    {
        $this->expectException(QueryException::class);
        DB::table('banjar_modules')->where('banjar_id', $this->a)->where('code', 'WEBSITE')->update(['status' => 'BASIC']);
    }

    private function loginTenant(): void
    {
        $this->post('http://alpha.banjar.localhost/login', ['email' => $this->admin->email, 'password' => $this->password])->assertRedirect('/admin');
    }

    private function setTenant(int $id): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->where('id', $id)->first());
    }

    public function test_resolver_exact_verified_domains_and_context_cleanup(): void
    {
        $this->get('http://alpha.banjar.localhost/login')->assertOk()->assertSee('Banjar alpha')->assertDontSee('Banjar beta');
        $this->assertNull(app(CurrentBanjar::class)->optional());
        $this->get('http://beta.banjar.localhost/login')->assertOk()->assertSee('Banjar beta')->assertDontSee('Banjar alpha');
        $this->get('http://unknown.banjar.localhost/login')->assertNotFound();
        $this->get('http://alpha.banjar.localhost.attacker.test/login')->assertNotFound();
        DB::table('tenant_domains')->insert(['banjar_id' => $this->a, 'domain' => 'alias.example.test', 'verification_status' => 'PENDING']);
        $this->get('http://alias.example.test/login')->assertNotFound();
        DB::table('tenant_domains')->where('domain', 'alias.example.test')->update(['verification_status' => 'VERIFIED', 'verified_at' => now()]);
        $this->get('http://alias.example.test/login')->assertOk()->assertSee('Banjar alpha');
        DB::table('banjars')->where('id', $this->a)->update(['status' => 'SUSPENDED']);
        $this->get('http://alpha.banjar.localhost/login')->assertNotFound();
        $this->get('http://alias.example.test/login')->assertNotFound();
    }

    public function test_forwarded_host_does_not_choose_tenant(): void
    {
        $this->withHeaders(['X-Forwarded-Host' => 'beta.banjar.localhost'])->get('http://alpha.banjar.localhost/login')->assertOk()->assertSee('Banjar alpha')->assertDontSee('Banjar beta');
    }

    public function test_tenant_login_dashboard_logout_and_cache_headers(): void
    {
        $this->get('http://alpha.banjar.localhost/admin')->assertRedirect('/login');
        $this->loginTenant();
        $this->get('http://alpha.banjar.localhost/admin')->assertOk()->assertSee('Admin alpha')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->post('http://alpha.banjar.localhost/logout')->assertRedirect('/login');
        $this->get('http://alpha.banjar.localhost/admin')->assertRedirect('/login');
        $this->assertDatabaseHas('audit_logs', ['banjar_id' => $this->a, 'action' => 'auth.logout', 'actor_id' => $this->admin->id]);
    }

    public function test_wrong_tenant_login_and_platform_role_do_not_grant_tenant_access(): void
    {
        $this->post('http://beta.banjar.localhost/login', ['email' => $this->admin->email, 'password' => $this->password])->assertSessionHasErrors('email');
        $this->post('http://alpha.banjar.localhost/login', ['email' => $this->super->email, 'password' => $this->password])->assertSessionHasErrors('email');
        $this->post('http://admin.banjar.localhost/login', ['email' => $this->admin->email, 'password' => $this->password])->assertSessionHasErrors('email');
    }

    public function test_copied_session_and_reassigned_domain_are_rejected(): void
    {
        $this->loginTenant();
        $this->get('http://beta.banjar.localhost/admin')->assertRedirect('/login');
        $this->loginTenant();
        DB::table('banjars')->where('id', $this->a)->update(['primary_domain_id' => null]);
        DB::table('tenant_domains')->where('domain', 'alpha.banjar.localhost')->update(['banjar_id' => $this->b]);
        $this->get('http://alpha.banjar.localhost/admin')->assertRedirect('/login');
    }

    public function test_membership_and_user_revocation_apply_to_existing_sessions(): void
    {
        $this->loginTenant();
        DB::table('banjar_user_memberships')->where('banjar_id', $this->a)->update(['status' => 'INACTIVE']);
        $this->get('http://alpha.banjar.localhost/admin')->assertForbidden();
        DB::table('banjar_user_memberships')->where('banjar_id', $this->a)->update(['status' => 'ACTIVE']);
        DB::table('users')->where('id', $this->admin->id)->update(['status' => 'INACTIVE']);
        $this->get('http://alpha.banjar.localhost/admin')->assertForbidden();
    }

    public function test_platform_onboarding_status_and_route_separation(): void
    {
        $this->post('http://admin.banjar.localhost/login', ['email' => $this->super->email, 'password' => $this->password])->assertRedirect('/platform');
        $this->get('http://admin.banjar.localhost/platform')->assertOk()->assertSee('Banjar alpha')->assertSee('Banjar beta');
        $this->get('http://admin.banjar.localhost/admin')->assertNotFound();
        $this->get('http://alpha.banjar.localhost/platform')->assertNotFound();
        $this->post('http://admin.banjar.localhost/platform/banjars', ['name' => 'Banjar Gamma', 'slug' => 'gamma', 'admin_name' => 'Admin Gamma', 'admin_email' => 'gamma@example.test', 'admin_password' => $this->password, 'admin_password_confirmation' => $this->password])->assertRedirect();
        $gamma = DB::table('banjars')->where('slug', 'gamma')->first();
        $this->assertNotNull($gamma);
        $this->assertSame(9, DB::table('banjar_modules')->where('banjar_id', $gamma->id)->count());
        $this->patch('http://admin.banjar.localhost/platform/banjars/'.$gamma->id.'/status', ['status' => 'SUSPENDED'])->assertRedirect();
        $this->get('http://gamma.banjar.localhost/login')->assertNotFound();
        $this->patch('http://admin.banjar.localhost/platform/banjars/'.$gamma->id.'/status', ['status' => 'ACTIVE'])->assertRedirect();
        $this->get('http://gamma.banjar.localhost/login')->assertOk();
    }

    public function test_settings_idor_query_and_form_tenant_are_ignored(): void
    {
        $this->loginTenant();
        $aSettings = DB::table('banjar_settings')->where('banjar_id', $this->a)->value('id');
        $bSettings = DB::table('banjar_settings')->where('banjar_id', $this->b)->value('id');
        $this->get('http://alpha.banjar.localhost/admin/settings?banjar_id='.$this->b)->assertOk()->assertSee('/admin/settings/'.$aSettings, false)->assertDontSee('Banjar beta');
        $data = ['banjar_id' => $this->b, 'template_code' => 'template-01', 'accent_color' => '#123456'];
        $this->patch('http://alpha.banjar.localhost/admin/settings/'.$bSettings, $data)->assertNotFound();
        $this->patch('http://alpha.banjar.localhost/admin/settings/'.$aSettings, $data)->assertRedirect();
        $this->assertDatabaseHas('banjar_settings', ['banjar_id' => $this->a, 'accent_color' => '#123456']);
        $this->assertDatabaseHas('banjar_settings', ['banjar_id' => $this->b, 'accent_color' => '#b94715']);
        $this->get('http://alpha.banjar.localhost/admin/audit')->assertOk()->assertDontSee('Banjar #'.$this->b);
        $this->setTenant($this->a);
        $this->assertFalse(app(TenantQuery::class)->table('audit_logs')->where('banjar_id', $this->b)->exists());
    }

    public function test_module_off_hides_navigation_and_blocks_route_and_direct_service(): void
    {
        $this->loginTenant();
        app(TenantManager::class)->module($this->super, $this->a, 'WEBSITE', ['status' => 'INACTIVE']);
        $this->get('http://alpha.banjar.localhost/admin')->assertOk()->assertDontSee('href="/admin/website"', false)->assertDontSee('href="/admin/settings"', false);
        $this->get('http://alpha.banjar.localhost/admin/website')->assertForbidden();
        $this->get('http://alpha.banjar.localhost/admin/settings')->assertForbidden();
        $this->setTenant($this->a);
        $this->expectException(HttpException::class);
        app(SettingsManager::class)->update($this->admin, DB::table('banjar_settings')->where('banjar_id', $this->a)->value('id'), ['template_code' => 'template-01', 'accent_color' => '#123456']);
    }

    public function test_permission_blocks_route_and_service_even_with_active_module(): void
    {
        $this->loginTenant();
        DB::table('tenant_role_assignments')->where('banjar_id', $this->a)->update(['role_id' => DB::table('roles')->where('code', 'PETENGEN')->value('id')]);
        $this->get('http://alpha.banjar.localhost/admin')->assertOk()->assertDontSee('href="/admin/settings"', false);
        $this->get('http://alpha.banjar.localhost/admin/settings')->assertForbidden();
        $this->setTenant($this->a);
        $this->expectException(HttpException::class);
        app(SettingsManager::class)->update($this->admin, 1, ['template_code' => 'template-01', 'accent_color' => '#123456']);
    }

    public function test_module_dependencies_and_basic_activity(): void
    {
        $this->setTenant($this->a);
        $this->assertTrue(app(ModuleGate::class)->allows('ACTIVITY'));
        $this->assertFalse(app(ModuleGate::class)->allows('ACTIVITY', 'ACTIVE'));
        $this->assertFalse(app(ModuleGate::class)->allows('PAYMENT'));
        try {
            app(TenantManager::class)->module($this->super, $this->a, 'PAYMENT', ['status' => 'ACTIVE']);
            $this->fail('Missing dependency accepted');
        } catch (ValidationException) {
        }
        try {
            app(TenantManager::class)->module($this->super, $this->a, 'WEBSITE', ['status' => 'BASIC']);
            $this->fail('BASIC WEBSITE accepted');
        } catch (ValidationException) {
        }
        foreach (['KRAMA', 'FINANCE', 'PAYMENT'] as $code) {
            app(TenantManager::class)->module($this->super, $this->a, $code, ['status' => 'ACTIVE']);
        }
        $this->assertTrue(app(ModuleGate::class)->allows('PAYMENT'));
        $this->expectException(ValidationException::class);
        app(TenantManager::class)->module($this->super, $this->a, 'KRAMA', ['status' => 'INACTIVE']);
    }

    public function test_platform_service_cannot_be_called_by_tenant_admin(): void
    {
        $this->expectException(HttpException::class);
        app(TenantManager::class)->status($this->admin, $this->b, ['status' => 'SUSPENDED']);
    }

    public function test_cross_tenant_membership_foreign_key_is_enforced_by_mysql(): void
    {
        $this->expectException(QueryException::class);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->b, 'membership_id' => DB::table('banjar_user_memberships')->where('banjar_id', $this->a)->value('id'), 'role_id' => DB::table('roles')->where('code', 'KELIAN')->value('id')]);
    }

    public function test_cross_tenant_primary_domain_foreign_key_is_enforced_by_mysql(): void
    {
        $this->expectException(QueryException::class);
        DB::table('banjars')->where('id', $this->a)->update(['primary_domain_id' => DB::table('tenant_domains')->where('banjar_id', $this->b)->value('id')]);
    }

    public function test_platform_role_cannot_be_assigned_as_tenant_role(): void
    {
        $this->expectException(QueryException::class);
        DB::table('tenant_role_assignments')->where('banjar_id', $this->a)->update(['role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
    }

    public function test_context_is_required_and_keys_are_tenant_specific(): void
    {
        $this->setTenant($this->a);
        $aKey = app(CurrentBanjar::class)->cacheKey('modules');
        $this->setTenant($this->b);
        $this->assertNotSame($aKey, app(CurrentBanjar::class)->cacheKey('modules'));
        app(CurrentBanjar::class)->clear();
        $this->expectException(\LogicException::class);
        app(TenantQuery::class)->table('banjar_settings')->get();
    }

    public function test_login_is_rate_limited_and_failed_attempt_does_not_authenticate(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $result = $this->post('http://alpha.banjar.localhost/login', ['email' => $this->admin->email, 'password' => 'wrong']);
        }
        $result->assertSessionHasErrors('email');
        $this->assertTrue(RateLimiter::tooManyAttempts('login:'.hash('sha256', $this->admin->email.'|127.0.0.1'), 5));
        $this->get('http://alpha.banjar.localhost/admin')->assertRedirect('/login');
    }

    public function test_manifest_is_tenant_aware_and_no_credentials_enter_audit(): void
    {
        $this->get('http://alpha.banjar.localhost/manifest.webmanifest')->assertOk()->assertJsonPath('name', 'Banjar alpha')->assertJsonPath('scope', '/');
        $this->get('http://beta.banjar.localhost/manifest.webmanifest')->assertJsonPath('name', 'Banjar beta');
        $this->assertStringNotContainsString($this->password, DB::table('audit_logs')->get()->toJson());
        $this->assertStringNotContainsString('admin_password', DB::table('audit_logs')->get()->toJson());
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Activity\ActivityManager;
use App\Modules\Administration\LetterManager;
use App\Modules\Website\ContentManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $tenant;

    private User $admin;

    private User $krama;

    private int $household;

    private int $person;

    private string $host = 'http://financeqa.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Portal tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fixture = FinanceFixture::create();
        $this->tenant = $this->fixture['tenant'];
        $this->admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $this->context();
        $this->household = DB::table('krama')->where('id', $this->fixture['people'][0])->value('household_id');
        $this->person = $this->fixture['people'][0];
        $this->krama = $this->role('KRAMA');
        DB::table('krama')->where('id', $this->person)->update(['user_id' => $this->krama->id]);
    }

    private function context(?int $tenant = null): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant ?? $this->tenant));
    }

    private function role(string $code): User
    {
        $user = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'BANJAR']);

        return $user;
    }

    private function tenantLogin(User $user): self
    {
        return $this->actingAs($user, 'web')->withSession(['auth_host' => 'financeqa.localhost', 'auth_context' => (string) $this->tenant]);
    }

    private function module(string $code, string $status = 'ACTIVE'): void
    {
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', $code)->update(['status' => $status]);
    }

    public function test_dashboard_degrades_per_module_and_shows_greeting(): void
    {
        // Only KRAMA + FINANCE active: bill card needs PAYMENT too, so it stays hidden.
        $response = $this->tenantLogin($this->krama)->get($this->host.'/portal/beranda');
        $response->assertOk()->assertSee('Krama Uji 1')->assertDontSee('TAGIHAN SAYA')->assertDontSee('KEGIATAN TERDEKAT')->assertDontSee('PENGAJUAN');

        $this->module('PAYMENT');
        $this->context();
        DB::table('bills')->insert(['banjar_id' => $this->tenant, 'billing_event_id' => $this->fixture['event'], 'kk_id' => $this->household, 'household_code' => 'KK-QA-001', 'kind' => 'IURAN', 'gross_amount' => 75000, 'status' => 'UNPAID', 'created_at' => now(), 'updated_at' => now()]);
        $this->tenantLogin($this->krama)->get($this->host.'/portal/beranda')->assertOk()->assertSee('TAGIHAN SAYA')->assertSee('Rp 75.000')->assertSee('1 tagihan belum lunas');
    }

    public function test_activity_card_shows_nearest_upcoming_activity_for_own_household(): void
    {
        $this->module('ACTIVITY');
        $this->context();
        $activities = app(ActivityManager::class);
        $id = app(ContentManager::class)->save($this->admin, 'agenda', null, ['title' => 'Ngayah Bersih Setra', 'status' => 'PUBLISHED', 'starts_at' => now('Asia/Makassar')->addDays(2)->format('Y-m-d H:i'), 'location' => 'Setra Desa']);
        $activities->configure($this->admin, $id, ['kind' => 'NGAYAH', 'visibility' => 'PUBLIC', 'attendance_basis' => 'HOUSEHOLD', 'allow_representative' => '1']);
        $activities->addTarget($this->admin, $id, ['target_type' => 'ALL_HOUSEHOLDS']);
        $activities->generate($this->admin, $id);

        $this->tenantLogin($this->krama)->get($this->host.'/portal/beranda')->assertOk()->assertSee('KEGIATAN TERDEKAT')->assertSee('Ngayah Bersih Setra')->assertSee('Setra Desa');
    }

    public function test_letter_card_counts_only_own_pending_submissions(): void
    {
        $this->module('ADMINISTRATION');
        $this->context();
        $serviceId = app(ContentManager::class)->save($this->admin, 'layanan', null, ['title' => 'Surat Domisili', 'status' => 'PUBLISHED']);
        $letters = app(LetterManager::class);
        $letters->configure($this->admin, $serviceId, ['letters_enabled' => 1, 'letter_requires_approval' => false, 'letter_statement' => 'Yang bersangkutan tercatat sebagai krama Banjar.', 'letter_version' => DB::table('service_definitions')->find($serviceId)->letter_version]);
        $letters->submit($this->krama, [
            'service_definition_id' => $serviceId, 'purpose' => 'Keperluan administrasi', 'submission_key' => (string) Str::uuid(),
            'person_version' => DB::table('krama')->find($this->person)->version, 'household_version' => DB::table('households')->find($this->household)->version,
            'service_version' => DB::table('service_definitions')->find($serviceId)->letter_version,
        ]);

        $this->tenantLogin($this->krama)->get($this->host.'/portal/beranda')->assertOk()->assertSee('PENGAJUAN')->assertSee('1 sedang diproses');
    }

    public function test_unlinked_account_shows_friendly_message_not_error(): void
    {
        $unlinked = $this->role('KRAMA');
        $this->tenantLogin($unlinked)->get($this->host.'/portal/beranda')->assertOk()->assertSee('Akun belum terhubung')->assertDontSee('Krama Uji');
    }

    public function test_requires_krama_self_permission_and_krama_module(): void
    {
        $this->tenantLogin($this->admin)->get($this->host.'/portal/beranda')->assertForbidden();
        $this->module('KRAMA', 'INACTIVE');
        $this->tenantLogin($this->krama)->get($this->host.'/portal/beranda')->assertForbidden();
    }

    public function test_tenant_isolation_never_leaks_other_banjar_bills(): void
    {
        $this->module('PAYMENT');
        $this->context();
        $super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        $other = app(TenantManager::class)->create($super, ['name' => 'Banjar Lain', 'slug' => 'portal-other', 'admin_name' => 'Lain', 'admin_email' => 'portal-other@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        foreach (['KRAMA', 'FINANCE', 'PAYMENT'] as $code) {
            app(TenantManager::class)->module($super, $other, $code, ['status' => 'ACTIVE']);
        }
        $this->context($other);
        $otherHousehold = DB::table('households')->insertGetId(['banjar_id' => $other, 'code' => 'KK-OTHER', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $otherEvent = DB::table('billing_types')->insertGetId(['banjar_id' => $other, 'name' => 'Iuran', 'kind' => 'IURAN', 'default_amount' => 999999, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $otherBillingEvent = DB::table('billing_events')->insertGetId(['banjar_id' => $other, 'billing_type_id' => $otherEvent, 'kind' => 'IURAN', 'amount' => 999999, 'title' => 'Event lain', 'reference' => 'REF-OTHER', 'target' => 'ALL_ACTIVE', 'status' => 'ISSUED', 'requires_approval' => false, 'created_by' => $super->id, 'created_at' => now(), 'updated_at' => now(), 'issued_at' => now()]);
        DB::table('bills')->insert(['banjar_id' => $other, 'billing_event_id' => $otherBillingEvent, 'kk_id' => $otherHousehold, 'household_code' => 'KK-OTHER', 'kind' => 'IURAN', 'gross_amount' => 999999, 'status' => 'UNPAID', 'created_at' => now(), 'updated_at' => now()]);
        $this->context();

        $this->tenantLogin($this->krama)->get($this->host.'/portal/beranda')->assertOk()->assertDontSee('999.999')->assertSee('Tidak ada tagihan belum lunas');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $this->get($this->portal.'/manifest.webmanifest')->assertOk()->assertJson(['short_name' => 'Portal Krama', 'start_url' => '/portal/beranda'])
            ->assertJsonPath('icons.0.src', '/icons/icon-192.png');
        $this->get($this->portal.'/login')->assertSee('src="/images/balisantih-logo.png"', false)->assertDontSee('<span class="brand-mark"', false);
    }

    public function test_installed_portal_uses_logo_of_warga_banjar(): void
    {
        Storage::fake('local');
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagewebp($image);
        $path = 'banjars/'.$this->tenant.'/media/logo-portal.webp';
        Storage::disk('local')->put($path, ob_get_clean());
        $logo = DB::table('media_files')->insertGetId(['banjar_id' => $this->tenant, 'path' => $path, 'name' => 'Logo', 'mime' => 'image/webp', 'size' => 10, 'visibility' => 'PUBLIC', 'uploader_id' => $this->krama->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_profiles')->updateOrInsert(['banjar_id' => $this->tenant], ['logo_id' => $logo]);

        $this->login();
        $icon = '/brand/'.$this->tenant.'/icon-192.png?v='.$logo;
        $this->get($this->portal.'/manifest.webmanifest')->assertJsonPath('name', 'Portal Krama · Banjar Uji Keuangan')->assertJsonPath('icons.0.src', $icon);
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertSee('<link rel="apple-touch-icon" href="/brand/'.$this->tenant.'/apple-180.png?v='.$logo.'"', false);

        // Peramban mengambil ikon tanpa cookie; ikon hanya untuk Banjar yang Portal Krama-nya aktif.
        $this->post($this->portal.'/logout');
        // Ikon berversi (?v=logo) boleh disimpan browser: tidak diunduh ulang setiap pindah menu.
        $this->get($this->portal.'/brand/'.$this->tenant.'/icon-192.png')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'max-age=604800, public');
        $this->module($this->tenant, 'PORTAL', 'INACTIVE');
        $this->get($this->portal.'/brand/'.$this->tenant.'/icon-192.png')->assertNotFound();
    }

    // Menu bawah portal harus ringan: izin, modul, dan daftar Banjar dibaca sekali per halaman, bukan per menu.
    public function test_portal_menu_pages_read_access_once_per_request(): void
    {
        foreach (['ACTIVITY', 'ADMINISTRATION', 'NOTIFICATION'] as $code) {
            $this->module($this->tenant, $code);
        }
        $this->login();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        foreach (['/portal/beranda', '/notifications', '/portal', '/aktivitas', '/admin/krama/mine'] as $path) {
            $queries = [];
            $this->get($this->portal.$path)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            $repeated = array_keys(array_filter(array_count_values($queries), fn ($n) => $n > 1));
            $this->assertSame([], $repeated, $path.' menjalankan query yang sama lebih dari sekali.');
            $this->assertLessThanOrEqual(14, count($queries), $path.' menjalankan '.count($queries).' query.');
        }
    }

    // Pengumuman yang diterbitkan pengurus sampai ke warga di portal; kegiatan yang sedang berlangsung hari ini tetap tampil.
    public function test_portal_shows_current_announcements_and_todays_activity(): void
    {
        $this->module($this->tenant, 'ACTIVITY');
        $other = $this->otherBanjar('lain', 'Banjar Lain');
        $news = fn (array $data, ?int $tenant = null) => DB::table('announcements')->insertGetId($data + ['banjar_id' => $tenant ?? $this->tenant, 'body' => "Baris satu\nbaris dua", 'status' => 'PUBLISHED', 'priority' => 'NORMAL', 'published_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
        $news(['title' => 'Ngayah gotong royong', 'slug' => 'ngayah']);
        $news(['title' => 'Berita duka', 'slug' => 'duka', 'priority' => 'IMPORTANT', 'published_at' => now()->subDays(2)]);
        $news(['title' => 'Sudah lewat', 'slug' => 'lewat', 'expires_at' => now()->subMinute()]);
        $news(['title' => 'Masih draf', 'slug' => 'draf', 'status' => 'DRAFT']);
        $news(['title' => 'Milik Banjar lain', 'slug' => 'lain'], $other);
        $activity = fn (string $title, $starts, $ends = null) => DB::table('activities')->insert(['banjar_id' => $this->tenant, 'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title), 'status' => 'PUBLISHED', 'visibility' => 'PUBLIC', 'published_at' => now()->subHour(), 'starts_at' => $starts, 'ends_at' => $ends, 'created_at' => now(), 'updated_at' => now()]);
        $activity('Kegiatan kemarin', now('Asia/Makassar')->subDay()->startOfDay()->addHours(9)->utc());
        $activity('Sedang berlangsung', now()->subMinutes(5));

        $this->login();
        $home = $this->get($this->portal.'/portal/beranda')->assertOk()
            ->assertSeeInOrder(['PENGUMUMAN', 'Berita duka', 'Penting', 'Ngayah gotong royong'])
            ->assertSee('href="/portal/pengumuman/ngayah"', false)->assertSee('Sedang berlangsung')
            ->assertDontSee('Sudah lewat')->assertDontSee('Masih draf')->assertDontSee('Milik Banjar lain')->assertDontSee('Kegiatan kemarin');
        $this->get($this->portal.'/portal/pengumuman')->assertOk()->assertSee('Ngayah gotong royong')->assertSee('Berita duka')->assertDontSee('Milik Banjar lain');
        $this->get($this->portal.'/portal/pengumuman/ngayah')->assertOk()->assertSee('Ngayah gotong royong')->assertSee("Baris satu\nbaris dua")
            ->tap(function ($response) {
                // Halaman pengumuman termasuk Beranda di menu bawah, bukan "Surat saya".
                $this->assertMatchesRegularExpression('#href="/portal/beranda"\s+aria-current="page"#', $response->getContent());
                $this->assertDoesNotMatchRegularExpression('#href="/portal"\s+aria-current="page"#', $response->getContent());
            });
        $this->get($this->portal.'/portal/pengumuman/lewat')->assertOk()->assertSee('Masa berlaku pengumuman ini telah berakhir');
        foreach (['draf', 'lain', 'tidak-ada'] as $slug) {
            $this->get($this->portal.'/portal/pengumuman/'.$slug)->assertNotFound();
        }
        // Pengumuman mengikuti modul Website: dimatikan, bagian dan halamannya hilang.
        $this->module($this->tenant, 'WEBSITE', 'INACTIVE');
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertDontSee('Ngayah gotong royong');
        $this->get($this->portal.'/portal/pengumuman')->assertForbidden();
    }

    // Ajakan mengaktifkan push tampil di beranda portal hanya bila push dikonfigurasi dan modul Notifikasi aktif.
    public function test_portal_home_offers_push_only_when_configured(): void
    {
        $this->module($this->tenant, 'NOTIFICATION');
        $this->login();
        config(['webpush.enabled' => false]);
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertDontSee('Dapatkan pemberitahuan di HP');
        config(['webpush.enabled' => true, 'webpush.public_key' => 'kunci-publik-uji']);
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertSee('Dapatkan pemberitahuan di HP')->assertSee('data-vapid-key="kunci-publik-uji"', false);
        $this->module($this->tenant, 'NOTIFICATION', 'INACTIVE');
        $this->get($this->portal.'/portal/beranda')->assertOk()->assertDontSee('Dapatkan pemberitahuan di HP');
    }

    public function test_access_changes_within_one_request_are_not_stale(): void
    {
        $this->module($this->tenant, 'ACTIVITY', 'INACTIVE');
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->tenant));
        $gate = app(\App\Platform\Modules\ModuleGate::class);
        $this->assertFalse($gate->allows('ACTIVITY', 'ACTIVE'));
        app(TenantManager::class)->module($this->super, $this->tenant, 'ACTIVITY', ['status' => 'ACTIVE']);
        $this->assertTrue($gate->allows('ACTIVITY', 'ACTIVE'), 'Status modul harus segar setelah diubah dalam request yang sama.');
        app(CurrentBanjar::class)->clear();
    }
}

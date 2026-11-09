<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Website\ContentManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

// Notifikasi warga: pengumuman & kegiatan publik yang terbit (langsung atau terjadwal) dan tagihan untuk kepala KK.
class WargaNotificationTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $tenant;

    private User $admin;

    private User $warga;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Warga notification tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = FinanceFixture::create();
        $this->tenant = $this->fixture['tenant'];
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->whereIn('code', ['NOTIFICATION', 'WEBSITE', 'ACTIVITY', 'PAYMENT'])->update(['status' => 'ACTIVE']);
        $this->admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $this->warga = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->tenant, 'user_id' => $this->warga->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'KRAMA')->value('id'), 'scope_type' => 'BANJAR']);
        DB::table('krama')->where('id', $this->fixture['people'][0])->update(['user_id' => $this->warga->id]);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->tenant));
    }

    private function save(string $type, array $data): int
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->tenant));

        return app(ContentManager::class)->save($this->admin, $type, null, $data);
    }

    private function notices(string $type): \Illuminate\Support\Collection
    {
        return DB::table('notifications')->where('user_id', $this->warga->id)->where('type', $type)->get();
    }

    public function test_published_announcements_and_public_activities_notify_warga_once(): void
    {
        $this->save('pengumuman', ['title' => 'Ngayah gotong royong', 'status' => 'PUBLISHED', 'priority' => 'IMPORTANT']);
        $this->save('pengumuman', ['title' => 'Masih draf', 'status' => 'DRAFT', 'priority' => 'NORMAL']);
        $this->save('agenda', ['title' => 'Paruman krama', 'status' => 'PUBLISHED', 'visibility' => 'PUBLIC', 'starts_at' => now('Asia/Makassar')->addDay()->format('Y-m-d\TH:i'), 'location' => 'Bale Banjar']);
        // Kegiatan internal (diatur di modul Kegiatan) dan kegiatan yang sudah selesai tidak diumumkan.
        DB::table('activities')->insert(['banjar_id' => $this->tenant, 'title' => 'Rapat tertutup', 'slug' => 'rapat-tertutup', 'status' => 'PUBLISHED', 'visibility' => 'INTERNAL', 'published_at' => now()->subMinute(), 'starts_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('activities')->insert(['banjar_id' => $this->tenant, 'title' => 'Sudah selesai', 'slug' => 'sudah-selesai', 'status' => 'PUBLISHED', 'visibility' => 'PUBLIC', 'published_at' => now()->subMinute(), 'starts_at' => now()->subDays(3), 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('notifications:publish-due');
        $this->assertSame(0, DB::table('activities')->where('banjar_id', $this->tenant)->whereNull('notified_at')->count(), 'Semua kegiatan terbit sudah diproses.');

        $news = $this->notices('announcement.published');
        $this->assertCount(1, $news);
        $this->assertSame('Pengumuman penting', $news[0]->title);
        $this->assertSame('Ngayah gotong royong', $news[0]->body);
        $this->assertSame('/portal/pengumuman/ngayah-gotong-royong', $news[0]->url);
        $activity = $this->notices('activity.published');
        $this->assertCount(1, $activity);
        $this->assertStringContainsString('Paruman krama', $activity[0]->body);
        $this->assertStringContainsString('Bale Banjar', $activity[0]->body);
        $this->assertSame('/aktivitas', $activity[0]->url);

        // Scheduler dan edit berikutnya tidak mengirim ulang.
        Artisan::call('notifications:publish-due');
        $id = DB::table('announcements')->where('title', 'Ngayah gotong royong')->value('id');
        app(ContentManager::class)->save($this->admin, 'pengumuman', $id, ['title' => 'Ngayah gotong royong (diubah)', 'status' => 'PUBLISHED', 'priority' => 'IMPORTANT']);
        $this->assertCount(1, $this->notices('announcement.published'));
        $this->assertCount(1, $this->notices('activity.published'));
    }

    public function test_scheduled_announcement_notifies_only_when_it_is_published(): void
    {
        $this->save('pengumuman', ['title' => 'Piodalan besok', 'status' => 'PUBLISHED', 'priority' => 'NORMAL', 'published_at' => now('Asia/Makassar')->addHours(2)->format('Y-m-d\TH:i')]);
        Artisan::call('notifications:publish-due');
        $this->assertCount(0, $this->notices('announcement.published'));

        $this->travel(3)->hours();
        Artisan::call('notifications:publish-due');
        Artisan::call('notifications:publish-due');
        $this->assertCount(1, $this->notices('announcement.published'));
        $this->assertSame('Pengumuman baru', $this->notices('announcement.published')[0]->title);
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'notifications:publish-due'));
        $this->assertSame('* * * * *', $event?->expression);
    }

    public function test_no_notifications_when_module_is_off_or_for_other_banjars(): void
    {
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'NOTIFICATION')->update(['status' => 'INACTIVE']);
        $this->save('pengumuman', ['title' => 'Saat notifikasi mati', 'status' => 'PUBLISHED', 'priority' => 'NORMAL']);
        $this->assertCount(0, $this->notices('announcement.published'));
        // Menyalakan modul lagi tidak membanjiri warga dengan pengumuman lama.
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'NOTIFICATION')->update(['status' => 'ACTIVE']);
        Artisan::call('notifications:publish-due');
        $this->assertCount(0, $this->notices('announcement.published'));

        $super = User::whereIn('id', DB::table('platform_role_assignments')->select('user_id'))->firstOrFail();
        $other = app(TenantManager::class)->create($super, ['name' => 'Banjar Lain', 'slug' => 'lain', 'admin_name' => 'Admin Lain', 'admin_email' => 'lain@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        DB::table('banjar_modules')->where('banjar_id', $other)->where('code', 'NOTIFICATION')->update(['status' => 'ACTIVE']);
        DB::table('announcements')->insert(['banjar_id' => $other, 'title' => 'Milik Banjar lain', 'slug' => 'lain', 'status' => 'PUBLISHED', 'priority' => 'NORMAL', 'published_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('notifications:publish-due');
        $this->assertCount(0, DB::table('notifications')->where('user_id', $this->warga->id)->get());
    }

    public function test_issued_bills_notify_household_heads_without_amount(): void
    {
        $kesinoman = User::findOrFail($this->fixture['users']['KESINOMAN']);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->tenant));
        app(FinanceManager::class)->issue($kesinoman, $this->fixture['event']);

        $bills = $this->notices('bill.issued');
        $this->assertCount(1, $bills);
        $this->assertSame('Tagihan baru', $bills[0]->title);
        $this->assertStringContainsString('Iuran September 2026', $bills[0]->body);
        $this->assertStringNotContainsString('50', $bills[0]->body);
        $this->assertSame('/payments', $bills[0]->url);
        // Pengurus yang bukan kepala KK tidak menerima notifikasi tagihan.
        $this->assertSame(0, DB::table('notifications')->where('type', 'bill.issued')->where('user_id', '<>', $this->warga->id)->count());
    }
}

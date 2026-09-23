<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Administration\LetterManager;
use App\Modules\Finance\FinanceManager;
use App\Modules\Krama\ChangeManager;
use App\Modules\Krama\KramaManager;
use App\Modules\Notification\NotificationManager;
use App\Modules\Notification\SendPushNotification;
use App\Modules\Payment\WithdrawalManager;
use App\Modules\Website\ContentManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\WithdrawalFixture;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $tenant;

    private User $admin;

    private User $petengen;

    private User $kesinoman;

    private User $kelian;

    private NotificationManager $notifications;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Notification tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        $this->fixture = WithdrawalFixture::create();
        $this->tenant = $this->fixture['tenant'];
        $this->admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $this->petengen = User::findOrFail($this->fixture['users']['PETENGEN']);
        $this->kesinoman = User::findOrFail($this->fixture['users']['KESINOMAN']);
        $this->kelian = User::findOrFail($this->fixture['users']['KELIAN']);
        $this->context();
        $this->module('NOTIFICATION');
        $this->notifications = app(NotificationManager::class);
    }

    private function context(?int $tenant = null): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant ?? $this->tenant));
    }

    private function module(string $code, string $status = 'ACTIVE'): void
    {
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', $code)->update(['status' => $status]);
    }

    private function role(string $code): User
    {
        $user = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'BANJAR']);

        return $user;
    }

    private function unreadFor(User $user): int
    {
        return DB::table('notifications')->where('banjar_id', $this->tenant)->where('user_id', $user->id)->whereNull('read_at')->count();
    }

    public function test_letter_flow_notifies_verify_then_approve_holders(): void
    {
        $this->module('ADMINISTRATION');
        $this->context();
        $penyarikan = $this->role('PENYARIKAN');
        $krama = $this->role('KRAMA');
        $person = $this->fixture['people'][0];
        DB::table('krama')->where('id', $person)->update(['user_id' => $krama->id]);
        $serviceId = app(ContentManager::class)->save($this->admin, 'layanan', null, ['title' => 'Surat Domisili', 'status' => 'PUBLISHED']);
        $letters = app(LetterManager::class);
        $letters->configure($this->admin, $serviceId, ['letters_enabled' => 1, 'letter_requires_approval' => true, 'letter_statement' => 'Statement', 'letter_version' => DB::table('service_definitions')->find($serviceId)->letter_version]);

        $household = DB::table('krama')->find($person)->household_id;
        $id = $letters->submit($krama, [
            'service_definition_id' => $serviceId, 'purpose' => 'Keperluan administrasi', 'submission_key' => (string) Str::uuid(),
            'person_version' => DB::table('krama')->find($person)->version, 'household_version' => DB::table('households')->find($household)->version,
            'service_version' => DB::table('service_definitions')->find($serviceId)->letter_version,
        ]);
        $this->assertSame(1, $this->unreadFor($penyarikan));
        $this->assertSame(0, $this->unreadFor($this->kelian));

        $letters->review($penyarikan, $id, ['action' => 'verify']);
        $this->assertSame(1, $this->unreadFor($this->kelian));
    }

    public function test_change_request_notifies_verify_then_approve_holders(): void
    {
        $kesinoman = $this->role('KESINOMAN');
        $krama = app(KramaManager::class);
        $household = $krama->household($this->admin, null, ['code' => 'KK-NOTIF', 'status' => 'ACTIVE']);
        $person = $krama->person($this->admin, null, ['household_id' => $household, 'name' => 'Notif Test', 'status' => 'ACTIVE']);
        DB::table('banjar_settings')->where('banjar_id', $this->tenant)->update(['krama_changes_require_approval' => true]);
        $changes = app(ChangeManager::class);
        $id = $changes->submit($this->admin, $person, ['field' => 'phone', 'value' => '081234567890', 'reason' => 'Update nomor', 'version' => DB::table('krama')->find($person)->version]);
        $this->assertSame(1, $this->unreadFor($kesinoman));
        $this->assertSame(0, $this->unreadFor($this->kelian));

        $changes->review($kesinoman, $id, ['action' => 'verify']);
        $this->assertSame(1, $this->unreadFor($this->kelian));
    }

    public function test_dedosan_event_notifies_approve_holders_only_when_policy_requires(): void
    {
        $finance = app(FinanceManager::class);
        DB::table('banjar_settings')->where('banjar_id', $this->tenant)->update(['dedosan_requires_approval' => false]);
        $type = $finance->billingType($this->kesinoman, ['name' => 'Dedosan tanpa approval', 'kind' => 'DEDOSAN', 'default_amount' => 50000]);
        $finance->event($this->kesinoman, ['billing_type_id' => $type, 'title' => 'Event 1', 'reference' => (string) Str::uuid(), 'event_date' => now('Asia/Makassar')->toDateString()]);
        $this->assertSame(0, $this->unreadFor($this->kelian));

        DB::table('banjar_settings')->where('banjar_id', $this->tenant)->update(['dedosan_requires_approval' => true]);
        $type2 = $finance->billingType($this->kesinoman, ['name' => 'Dedosan dengan approval', 'kind' => 'DEDOSAN', 'default_amount' => 50000]);
        $finance->event($this->kesinoman, ['billing_type_id' => $type2, 'title' => 'Event 2', 'reference' => (string) Str::uuid(), 'event_date' => now('Asia/Makassar')->toDateString()]);
        $this->assertSame(1, $this->unreadFor($this->kelian));
    }

    public function test_withdrawal_request_and_resolution_notify_correctly(): void
    {
        DB::table('banjar_settings')->where('banjar_id', $this->tenant)->update(['payout_requires_kelian_approval' => true]);
        $withdrawals = app(WithdrawalManager::class);
        $id = $withdrawals->request($this->petengen, ['amount' => '10000', 'submission_key' => (string) Str::uuid()]);
        $this->assertSame(1, $this->unreadFor($this->kelian), 'Kelian is notified once approval is required.');

        $withdrawals->approve($this->kelian, $id, ['decision' => 'REJECT', 'note' => 'Belum lengkap dokumen.']);
        $this->assertSame(1, $this->unreadFor($this->petengen), 'Requester is notified their request was resolved.');
    }

    public function test_unread_count_mark_read_and_mark_all_read(): void
    {
        $a = $this->notify($this->kelian, 'test.a');
        $this->notify($this->kelian, 'test.b');
        $this->assertSame(2, $this->notifications->unreadCount($this->kelian));

        $this->notifications->markRead($this->kelian, $a);
        $this->assertSame(1, $this->notifications->unreadCount($this->kelian));

        $this->notifications->markAllRead($this->kelian);
        $this->assertSame(0, $this->notifications->unreadCount($this->kelian));
    }

    public function test_notification_disabled_when_module_inactive(): void
    {
        $this->module('NOTIFICATION', 'INACTIVE');
        $this->notify($this->kelian, 'test.silent');
        $this->assertSame(0, DB::table('notifications')->where('banjar_id', $this->tenant)->count());
    }

    public function test_subscribe_dispatches_push_job_and_unsubscribe_removes_it(): void
    {
        config(['webpush.enabled' => true]);
        $this->notifications->subscribe($this->kelian, [
            'endpoint' => 'https://push.example.test/abc', 'p256dh' => str_repeat('a', 88), 'auth' => str_repeat('b', 24),
        ]);
        $this->assertSame(1, DB::table('push_subscriptions')->where('banjar_id', $this->tenant)->where('user_id', $this->kelian->id)->count());

        $this->notify($this->kelian, 'test.push');
        Queue::assertPushed(SendPushNotification::class, 1);

        $this->notifications->unsubscribe($this->kelian, 'https://push.example.test/abc');
        $subscription = DB::table('push_subscriptions')->where('banjar_id', $this->tenant)->first();
        $this->assertNotNull($subscription->expires_at, 'Unsubscribe soft-expires rather than deleting a subscription with delivery history.');
    }

    public function test_notifications_never_leak_across_tenants(): void
    {
        $super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        $other = app(TenantManager::class)->create($super, ['name' => 'Banjar Lain', 'slug' => 'notif-other', 'admin_name' => 'Lain', 'admin_email' => 'notif-other@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        DB::table('banjar_modules')->where('banjar_id', $other)->where('code', 'NOTIFICATION')->update(['status' => 'ACTIVE']);
        $this->notify($this->kelian, 'test.other-tenant', $other);

        $this->assertSame(0, $this->unreadFor($this->kelian));
    }

    private function notify(User $user, string $type, ?int $tenant = null): int
    {
        $this->notifications->notify($tenant ?? $this->tenant, $user->id, $type, 'Judul', 'Isi generik.', '/admin');

        return (int) DB::table('notifications')->where('banjar_id', $tenant ?? $this->tenant)->where('user_id', $user->id)->where('type', $type)->orderByDesc('id')->value('id');
    }
}

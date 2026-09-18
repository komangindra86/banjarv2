<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Payment\BankAccountManager;
use App\Modules\Payment\LedgerService;
use App\Modules\Payment\WithdrawalManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WithdrawalFixture;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private const AVAILABLE = 148950; // 3 × (50.000 − 350 fee)

    private array $fixture;

    private User $petengen;

    private User $kelian;

    private User $operator;

    private WithdrawalManager $withdrawals;

    private string $host = 'http://financeqa.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Withdrawal tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fixture = WithdrawalFixture::create();
        $this->petengen = User::findOrFail($this->fixture['users']['PETENGEN']);
        $this->kelian = User::findOrFail($this->fixture['users']['KELIAN']);
        $this->operator = User::findOrFail($this->fixture['platform'][0]);
        $this->withdrawals = app(WithdrawalManager::class);
    }

    private function context(): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->fixture['tenant']));
    }

    private function balance(string $bucket): int
    {
        return app(LedgerService::class)->balance($this->fixture['tenant'], $bucket);
    }

    private function request(int $amount, ?User $actor = null, ?string $key = null): int
    {
        $this->context();

        return $this->withdrawals->request($actor ?? $this->petengen, ['amount' => (string) $amount, 'submission_key' => $key ?? (string) Str::uuid()]);
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->image('bukti.png', 200, 120);
    }

    private function paid(int $id, string $reference = 'TRF-2026-0001'): void
    {
        $this->withdrawals->markPaid($this->operator, $id, ['bank_reference' => $reference, 'transferred_at' => now('Asia/Makassar')->format('Y-m-d\TH:i'), 'proof' => $this->proof()]);
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

    private function consistent(): void
    {
        $this->assertSame([], $this->withdrawals->invariants($this->fixture['tenant']));
    }

    private function tenantSession(User $user): self
    {
        return $this->actingAs($user, 'web')->withSession(['auth_host' => 'financeqa.localhost', 'auth_context' => (string) $this->fixture['tenant']]);
    }

    private function platformSession(User $user): self
    {
        return $this->actingAs($user, 'platform')->withSession(['auth_host' => 'admin.banjar.localhost', 'auth_context' => 'platform']);
    }

    public function test_request_reserves_atomically_snapshots_verified_account_and_is_idempotent(): void
    {
        $this->assertSame(self::AVAILABLE, $this->balance('AVAILABLE'));
        $key = (string) Str::uuid();
        $id = $this->request(40000, null, $key);
        $this->assertSame($id, $this->request(40000, null, $key));
        $this->rejected(fn () => $this->request(41000, null, $key), 409);
        $w = DB::table('withdrawals')->find($id);
        $this->assertSame('REQUESTED', $w->status);
        $this->assertSame('**** 8219', $w->account_number_masked);
        $this->assertStringStartsWith('WD-', $w->reference);
        $this->assertStringNotContainsString('0123456789018219', $w->account_number_encrypted);
        $this->assertSame(self::AVAILABLE - 40000, $this->balance('AVAILABLE'));
        $this->assertSame(40000, $this->balance('RESERVED'));
        $this->assertSame(1, DB::table('financial_ledger_transactions')->where('withdrawal_id', $id)->where('type', 'reserve')->count());
        $this->assertDatabaseHas('withdrawal_steps', ['withdrawal_id' => $id, 'to_status' => 'REQUESTED', 'actor_id' => $this->petengen->id]);
        $this->consistent();
    }

    public function test_zero_excess_pending_funds_and_missing_account_are_rejected_without_movement(): void
    {
        foreach (['0', '-5', '1.5', 'abc', ''] as $amount) {
            $this->context();
            $this->invalid(fn () => $this->withdrawals->request($this->petengen, ['amount' => $amount, 'submission_key' => (string) Str::uuid()]), 'amount');
        }
        $this->invalid(fn () => $this->request(self::AVAILABLE + 1), 'amount');
        $this->assertSame(0, $this->balance('RESERVED'));
        $this->assertDatabaseCount('withdrawals', 0);
        // A destination typed into the form is ignored; the verified primary account is used.
        $this->context();
        $id = $this->withdrawals->request($this->petengen, ['amount' => '1000', 'submission_key' => (string) Str::uuid(), 'account_number' => '9999999999', 'bank_account_id' => 999]);
        $this->assertSame((int) $this->fixture['bank_account'], (int) DB::table('withdrawals')->where('id', $id)->value('bank_account_id'));
        // PENDING (unreleased) gateway money is never withdrawable.
        DB::table('banjar_bank_accounts')->update(['is_primary' => false, 'verification_status' => 'DISABLED']);
        $this->invalid(fn () => $this->request(1000), 'amount');
        $this->consistent();
    }

    public function test_optional_kelian_approval_self_approval_rejection_and_policy_snapshot(): void
    {
        $this->context();
        $admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $this->withdrawals->policy($admin, ['payout_requires_kelian_approval' => '1']);
        $id = $this->request(30000);
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'APPROVAL_PENDING', 'requires_kelian_approval' => true]);
        $this->rejected(fn () => $this->withdrawals->claim($this->operator, $id), 409);
        // Petengen who also holds Kelian rights cannot approve their own request.
        $member = DB::table('banjar_user_memberships')->where('user_id', $this->petengen->id)->value('id');
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->fixture['tenant'], 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'KELIAN')->value('id'), 'scope_type' => 'BANJAR']);
        $this->rejected(fn () => $this->withdrawals->approve($this->petengen, $id, ['decision' => 'APPROVE']), 403);
        $this->rejected(fn () => $this->withdrawals->approve($admin, $id, ['decision' => 'APPROVE']), 403);
        $this->withdrawals->approve($this->kelian, $id, ['decision' => 'APPROVE']);
        $this->withdrawals->approve($this->kelian, $id, ['decision' => 'APPROVE']);
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'REQUESTED', 'approved_by' => $this->kelian->id]);

        $second = $this->request(20000);
        $this->invalid(fn () => $this->withdrawals->approve($this->kelian, $second, ['decision' => 'REJECT']), 'note');
        $this->withdrawals->approve($this->kelian, $second, ['decision' => 'REJECT', 'note' => 'Belum diputuskan paruman']);
        $this->assertDatabaseHas('withdrawals', ['id' => $second, 'status' => 'CANCELLED']);
        $this->assertSame(30000, $this->balance('RESERVED'));

        $this->withdrawals->policy($admin, ['payout_requires_kelian_approval' => '0']);
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'requires_kelian_approval' => true]);
        $this->assertDatabaseHas('withdrawals', ['id' => $this->request(1000), 'status' => 'REQUESTED', 'requires_kelian_approval' => false]);
        $this->consistent();
    }

    public function test_claim_mark_paid_requires_evidence_and_double_submit_posts_one_paid_journal(): void
    {
        Storage::fake('local');
        $id = $this->request(40000);
        $other = WithdrawalFixture::platformUser();
        $this->rejected(fn () => $this->withdrawals->markPaid($this->operator, $id, ['bank_reference' => 'TRF-1', 'transferred_at' => now('Asia/Makassar')->format('Y-m-d\TH:i'), 'proof' => $this->proof()]), 409);
        $this->withdrawals->claim($this->operator, $id);
        $this->withdrawals->claim($this->operator, $id);
        $this->rejected(fn () => $this->withdrawals->claim($other, $id), 409);
        $this->rejected(fn () => $this->withdrawals->revealDestination($other, $id), 403);
        $this->assertSame('0123456789018219', $this->withdrawals->revealDestination($this->operator, $id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.destination_revealed', 'entity_id' => $id, 'actor_id' => $this->operator->id]);
        $this->context();
        $this->rejected(fn () => $this->withdrawals->cancel($this->petengen, $id, ['note' => 'Batal setelah diklaim']), 409);

        $this->invalid(fn () => $this->withdrawals->markPaid($this->operator, $id, ['bank_reference' => 'TRF-2026-0001', 'transferred_at' => now('Asia/Makassar')->format('Y-m-d\TH:i')]), 'proof');
        $this->invalid(fn () => $this->withdrawals->markPaid($this->operator, $id, ['transferred_at' => now('Asia/Makassar')->format('Y-m-d\TH:i'), 'proof' => $this->proof()]), 'bank_reference');
        $this->invalid(fn () => $this->withdrawals->markPaid($this->operator, $id, ['bank_reference' => 'TRF-2026-0001', 'transferred_at' => now('Asia/Makassar')->addDay()->format('Y-m-d\TH:i'), 'proof' => $this->proof()]), 'transferred_at');
        $this->invalid(fn () => $this->withdrawals->markPaid($this->operator, $id, ['bank_reference' => 'TRF-2026-0001', 'transferred_at' => now('Asia/Makassar')->format('Y-m-d\TH:i'), 'proof' => UploadedFile::fake()->createWithContent('bukti.php', '<?php echo 1;')]), 'proof');
        $this->rejected(fn () => $this->withdrawals->markPaid($other, $id, ['bank_reference' => 'TRF-2026-0001', 'transferred_at' => now('Asia/Makassar')->format('Y-m-d\TH:i'), 'proof' => $this->proof()]), 403);
        $this->assertCount(0, Storage::disk('local')->allFiles());

        $this->paid($id);
        $this->paid($id);
        $this->rejected(fn () => $this->paid($id, 'TRF-LAIN-99'), 409);
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'Rejected or duplicate submissions must not leave orphan proofs.');
        $w = DB::table('withdrawals')->find($id);
        $this->assertSame('PAID', $w->status);
        $this->assertSame($this->operator->id, (int) $w->processed_by);
        $this->assertStringNotContainsString('TRF-2026-0001', $w->bank_reference_encrypted);
        $this->assertSame(1, DB::table('financial_ledger_transactions')->where('withdrawal_id', $id)->where('type', 'paid')->count());
        $this->assertSame(0, $this->balance('RESERVED'));
        $this->assertSame(40000, $this->balance('PAID_OUT'));
        $this->assertSame(self::AVAILABLE - 40000, $this->balance('AVAILABLE'));
        $this->rejected(fn () => $this->withdrawals->markFailed($this->operator, $id, ['note' => 'Coba gagalkan setelah dibayar', 'confirm_not_sent' => '1']), 409);
        $this->assertStringStartsWith('RIFF', $this->withdrawals->proof($w));

        // The same bank reference cannot close a second withdrawal.
        $next = $this->request(1000);
        $this->withdrawals->claim($this->operator, $next);
        $this->invalid(fn () => $this->paid($next, 'trf-2026-0001'), 'bank_reference');
        $this->consistent();
    }

    public function test_failed_and_cancelled_release_reserved_once_and_cannot_be_reopened(): void
    {
        $failed = $this->request(50000);
        $this->withdrawals->claim($this->operator, $failed);
        $this->invalid(fn () => $this->withdrawals->markFailed($this->operator, $failed, ['note' => 'Rekening tujuan menolak dana']), 'confirm_not_sent');
        $this->withdrawals->markFailed($this->operator, $failed, ['note' => 'Rekening tujuan menolak dana', 'confirm_not_sent' => '1']);
        $this->withdrawals->markFailed($this->operator, $failed, ['note' => 'Rekening tujuan menolak dana', 'confirm_not_sent' => '1']);
        $cancelled = $this->request(25000);
        $this->context();
        $this->withdrawals->cancel($this->petengen, $cancelled, ['note' => 'Nominal salah input']);
        $this->withdrawals->cancel($this->petengen, $cancelled, ['note' => 'Nominal salah input']);
        $declined = $this->request(10000);
        $this->withdrawals->platformCancel($this->operator, $declined, ['note' => 'Dokumen pendukung belum lengkap']);
        foreach ([$failed, $cancelled, $declined] as $id) {
            $this->assertSame(1, DB::table('financial_ledger_transactions')->where('withdrawal_id', $id)->where('type', 'release')->count());
            $this->rejected(fn () => $this->withdrawals->claim($this->operator, $id), 409);
        }
        $this->assertSame(0, $this->balance('RESERVED'));
        $this->assertSame(self::AVAILABLE, $this->balance('AVAILABLE'));
        $this->assertSame(0, $this->balance('PAID_OUT'));
        $this->consistent();
    }

    public function test_account_change_keeps_snapshot_and_blocked_account_stops_processing(): void
    {
        $old = $this->request(10000);
        $this->context();
        app(BankAccountManager::class)->submit($this->petengen, ['bank_code' => '002', 'account_number' => '5555-6666-7777', 'account_holder_name' => 'Banjar Uji Baru']);
        $this->invalid(fn () => app(BankAccountManager::class)->submit($this->petengen, ['bank_code' => '009', 'account_number' => '1111222233', 'account_holder_name' => 'Pengajuan kedua']), 'account_number');
        $new = DB::table('banjar_bank_accounts')->where('verification_status', 'PENDING')->value('id');
        $this->rejected(fn () => app(BankAccountManager::class)->review($this->petengen, $new, ['decision' => 'VERIFY']), 403);
        app(BankAccountManager::class)->review($this->operator, $new, ['decision' => 'VERIFY']);
        $this->assertDatabaseHas('banjar_bank_accounts', ['id' => $this->fixture['bank_account'], 'verification_status' => 'REPLACED', 'is_primary' => false]);
        $this->assertSame(1, DB::table('banjar_bank_accounts')->where('banjar_id', $this->fixture['tenant'])->where('is_primary', true)->count());
        $this->assertDatabaseHas('withdrawals', ['id' => $old, 'account_number_masked' => '**** 8219', 'bank_name' => 'BPD Bali']);
        $this->assertDatabaseHas('withdrawals', ['id' => $this->request(1000), 'account_number_masked' => '**** 7777', 'bank_name' => 'BRI']);
        $this->withdrawals->claim($this->operator, $old);
        $this->assertSame('555566667777', app(BankAccountManager::class)->reveal($this->operator, $new));
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank_account.number_revealed', 'entity_id' => $new]);

        $blocked = $this->request(2000);
        $this->invalid(fn () => app(BankAccountManager::class)->review($this->operator, $new, ['decision' => 'DISABLE']), 'note');
        app(BankAccountManager::class)->review($this->operator, $new, ['decision' => 'DISABLE', 'note' => 'Laporan penyalahgunaan rekening']);
        $this->rejected(fn () => $this->withdrawals->claim($this->operator, $blocked), 409);
        $this->invalid(fn () => $this->request(1000), 'amount');
        $this->rejected(fn () => app(BankAccountManager::class)->reveal($this->operator, $new), 404);
        $logs = DB::table('audit_logs')->where('entity_type', 'banjar_bank_accounts')->get();
        $this->assertStringNotContainsString('0123456789018219', $logs->toJson());
        $this->assertStringNotContainsString('555566667777', $logs->toJson());
        $this->consistent();
    }

    public function test_permissions_tenant_isolation_and_module_gate(): void
    {
        $id = $this->request(5000);
        $this->context();
        $this->rejected(fn () => $this->withdrawals->request($this->kelian, ['amount' => '1000', 'submission_key' => (string) Str::uuid()]), 403);
        $this->rejected(fn () => app(BankAccountManager::class)->submit($this->kelian, ['bank_code' => '129', 'account_number' => '123456789', 'account_holder_name' => 'Kelian']), 403);
        $this->rejected(fn () => $this->withdrawals->claim($this->petengen, $id), 403);
        $this->rejected(fn () => $this->withdrawals->markPaid($this->petengen, $id, []), 403);
        $admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $this->rejected(fn () => $this->withdrawals->cancel($admin, $id, ['note' => 'Admin mencoba batal']), 403);

        $super = WithdrawalFixture::platformUser();
        $other = app(TenantManager::class)->create($super, ['name' => 'Banjar Lain', 'slug' => 'withdraw-other', 'admin_name' => 'Lain', 'admin_email' => 'withdraw-other@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        foreach (['KRAMA', 'FINANCE', 'PAYMENT'] as $code) {
            app(TenantManager::class)->module($super, $other, $code, ['status' => 'ACTIVE']);
        }
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $other, 'user_id' => $this->petengen->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $other, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'PETENGEN')->value('id'), 'scope_type' => 'BANJAR']);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($other));
        $this->rejected(fn () => $this->withdrawals->visible($this->petengen, $id), 404);
        $this->rejected(fn () => $this->withdrawals->cancel($this->petengen, $id, ['note' => 'Lintas tenant']), 404);
        $this->invalid(fn () => $this->withdrawals->request($this->petengen, ['amount' => '1000', 'submission_key' => (string) Str::uuid()]), 'amount');

        $this->tenantSession($this->kelian)->get($this->host.'/admin/withdrawals')->assertOk()->assertSee('Rp 143.950')->assertDontSee('Ajukan pencairan');
        $this->tenantSession($this->petengen)->get($this->host.'/admin/withdrawals/'.$id)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertDontSee('0123456789018219');
        $this->tenantSession($this->petengen)->post($this->host.'/admin/withdrawals', ['amount' => '1000', 'submission_key' => (string) Str::uuid()])->assertRedirect();
        $this->platformSession($this->operator)->get('http://admin.banjar.localhost/platform/withdrawals')->assertOk()->assertSee('Banjar Uji Keuangan')->assertDontSee('0123456789018219');
        $this->tenantSession($this->petengen)->get('http://admin.banjar.localhost/platform/withdrawals')->assertRedirect('/login');
        $krama = User::factory()->create();
        $km = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->fixture['tenant'], 'user_id' => $krama->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->fixture['tenant'], 'membership_id' => $km, 'role_id' => DB::table('roles')->where('code', 'KRAMA')->value('id'), 'scope_type' => 'BANJAR']);
        $this->tenantSession($krama)->get($this->host.'/admin/withdrawals')->assertForbidden();
        DB::table('banjar_modules')->where('banjar_id', $this->fixture['tenant'])->where('code', 'PAYMENT')->update(['status' => 'INACTIVE']);
        $this->tenantSession($this->petengen)->get($this->host.'/admin/withdrawals')->assertForbidden();
        // Module off stops new requests, but the platform can still settle an existing obligation.
        $this->withdrawals->claim($this->operator, $id);
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'PROCESSING']);
    }

    public function test_platform_http_flow_claim_upload_and_proof_download(): void
    {
        $id = $this->request(12345);
        $url = 'http://admin.banjar.localhost/platform/withdrawals/'.$id;
        $this->platformSession($this->operator)->post($url.'/claim')->assertRedirect();
        $this->platformSession($this->operator)->post($url.'/reveal')->assertOk()->assertSee('0123456789018219');
        $this->platformSession($this->operator)->post($url.'/paid', ['bank_reference' => 'BPD-778899', 'transferred_at' => now('Asia/Makassar')->format('Y-m-d\TH:i'),
            'proof' => UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")])->assertRedirect('/platform/withdrawals/'.$id);
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'PAID', 'proof_mime' => 'application/pdf']);
        $this->platformSession($this->operator)->get($url.'/proof')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->tenantSession($this->kelian)->get($this->host.'/admin/withdrawals/'.$id.'/proof')->assertOk();
        $this->tenantSession($this->petengen)->get($this->host.'/admin/payments')->assertOk()->assertSee('Pencairan #'.$id);
        $this->consistent();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Payment\LedgerService;
use App\Modules\Payment\PaymentManager;
use App\Modules\Payment\ProcessPaymentReceipt;
use App\Modules\Payment\SettlementManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FinanceFixture;
use Tests\Support\IpaymuFake;
use Tests\TestCase;

// QRIS lewat iPaymu (§29, §43): warga membayar tagihan + biaya layanan 3,5%; Banjar menerima tagihan utuh;
// biaya layanan dikurangi biaya iPaymu adalah pendapatan platform. Status lunas hanya dari API iPaymu.
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private User $actor;

    private int $bill;

    private IpaymuFake $ipaymu;

    private string $webhook = 'http://admin.banjar.localhost/webhooks/ipaymu';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Payment tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        IpaymuFake::configure();
        $this->fixture = FinanceFixture::create();
        DB::table('banjar_modules')->where('banjar_id', $this->fixture['tenant'])->where('code', 'PAYMENT')->update(['status' => 'ACTIVE']);
        $this->actor = User::findOrFail($this->fixture['users']['PETENGEN']);
        app(FinanceManager::class)->issue(User::findOrFail($this->fixture['users']['KESINOMAN']), $this->fixture['event']);
        $this->bill = DB::table('bills')->orderBy('id')->value('id');
        $this->ipaymu = (new IpaymuFake)->install();
    }

    private function context(): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->fixture['tenant']));
    }

    private function create(): object
    {
        $this->context();
        $id = app(PaymentManager::class)->create(FinanceFixture::payer($this->fixture['tenant'], $this->bill), $this->bill);

        return DB::table('payments')->find($id);
    }

    private function paid(): object
    {
        $p = $this->create();
        $this->ipaymu->statuses[$p->order_id] = 1;
        app(PaymentManager::class)->sync($p->id);

        return DB::table('payments')->find($p->id);
    }

    private function notice(object $p, array $extra = []): array
    {
        return $extra + ['trx_id' => IpaymuFake::transactionId($p), 'reference_id' => $p->order_id, 'status' => 'berhasil', 'status_code' => '1'];
    }

    private function balance(string $bucket): int
    {
        return app(LedgerService::class)->balance($this->fixture['tenant'], $bucket);
    }

    private function platformUser(): User
    {
        $user = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);

        return $user;
    }

    private function tenantSession(?User $user = null): self
    {
        return $this->actingAs($user ?? $this->actor)->withSession(['auth_host' => 'financeqa.localhost', 'auth_context' => (string) $this->fixture['tenant']]);
    }

    private function rejected(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Action unexpectedly accepted');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_service_fee_is_three_and_a_half_percent_rounded_up_to_the_rupiah(): void
    {
        $this->assertSame([1750, 70, 4, 1, 0], [PaymentManager::serviceFee(50000), PaymentManager::serviceFee(2000), PaymentManager::serviceFee(100), PaymentManager::serviceFee(1), PaymentManager::serviceFee(0)]);
        config(['payment.service_fee_percent' => 0]);
        $this->assertSame(0, PaymentManager::serviceFee(50000));
    }

    public function test_charge_adds_service_fee_signs_request_and_reuses_active_attempt(): void
    {
        $p = $this->create();
        $this->assertSame(['PENDING', 50000, 1750, 51750], [$p->status, (int) $p->bill_amount, (int) $p->service_fee, (int) $p->gross_amount]);
        $this->assertSame(IpaymuFake::transactionId($p), $p->provider_transaction_id);
        $this->assertNotEmpty($p->qr_string);
        $this->assertSame($p->id, $this->create()->id);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $signature = hash_hmac('sha256', 'POST:'.IpaymuFake::VA.':'.strtolower(hash('sha256', $request->body())).':'.IpaymuFake::KEY, IpaymuFake::KEY);

            return str_ends_with($request->url(), 'sandbox.ipaymu.com/api/v2/payment/direct') && $request->header('signature')[0] === $signature && $request->header('va')[0] === IpaymuFake::VA
                && $body['amount'] === 51750 && $body['price'] === [50000, 1750] && $body['paymentMethod'] === 'qris' && $body['feeDirection'] === 'MERCHANT'
                && $body['notifyUrl'] === rtrim((string) config('app.url'), '/').'/webhooks/ipaymu' && str_contains($body['product'][0], 'Banjar Uji Keuangan');
        });
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, $this->balance('PENDING'));
    }

    public function test_paid_qris_gives_banjar_the_full_bill_and_platform_the_service_fee_minus_ipaymu_fee(): void
    {
        $this->ipaymu->fee = 600;
        $p = $this->paid();
        $this->assertSame(['PAID', 50000, 600, 1150], [$p->status, (int) $p->net_amount, (int) $p->gateway_fee, (int) $p->platform_fee]);
        $this->assertSame(50000, $this->balance('PENDING'), 'Banjar menerima nilai tagihan utuh.');
        $this->assertSame(1150, $this->balance('PLATFORM'));
        $this->assertSame(600, $this->balance('FEE'));
        $this->assertSame(-51750, $this->balance('CLEARING'));
        $this->assertDatabaseHas('bill_settlements', ['payment_id' => $p->id, 'method' => 'GATEWAY', 'amount' => 50000]);
        $this->assertDatabaseHas('bills', ['id' => $this->bill, 'status' => 'PAID']);
    }

    public function test_timeout_is_unknown_and_never_recharges(): void
    {
        $this->ipaymu->timeout = true;
        $p = $this->create();
        $this->assertSame('UNKNOWN', $p->status);
        $this->ipaymu->timeout = false;
        $this->assertSame($p->id, $this->create()->id);
        try {
            app(PaymentManager::class)->sync($p->id);
        } catch (\RuntimeException) {
        }
        $this->assertSame($p->id, $this->create()->id);
        $this->assertDatabaseCount('payments', 1);
        // Satu-satunya pembuatan QRIS timeout; tidak ada pembuatan ulang (status belum bisa dibaca tanpa nomor transaksi).
        Http::assertSentCount(0);
    }

    public function test_callback_before_charge_response_cannot_downgrade_paid(): void
    {
        $this->ipaymu->onCharge = function (object $payment, string $trx) {
            $this->ipaymu->statuses[$payment->order_id] = 1;
            app(PaymentManager::class)->processReceipt(app(PaymentManager::class)->receive(['trx_id' => $trx, 'reference_id' => $payment->order_id]));
        };
        $p = $this->create();
        $this->assertSame('PAID', $p->status);
        $this->assertSame(50000, $this->balance('PENDING'));
        $this->assertDatabaseCount('bill_settlements', 1);
    }

    public function test_duplicate_callbacks_are_durable_redacted_and_credit_once(): void
    {
        Queue::fake();
        $p = $this->create();
        $this->ipaymu->statuses[$p->order_id] = 1;
        $payload = $this->notice($p, ['buyer_name' => 'PRIVATE-PERSON', 'buyer_phone' => '0812PRIVATE']);
        $this->post($this->webhook, $payload)->assertOk();
        $this->post($this->webhook, $payload)->assertOk();
        $this->assertDatabaseCount('payment_webhook_receipts', 1);
        $receipt = DB::table('payment_webhook_receipts')->first();
        $this->assertStringNotContainsString('PRIVATE', $receipt->payload);
        Queue::assertPushed(ProcessPaymentReceipt::class);
        app(PaymentManager::class)->processReceipt($receipt->id);
        app(PaymentManager::class)->processReceipt($receipt->id);
        app(PaymentManager::class)->sync($p->id);
        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertDatabaseCount('financial_ledger_transactions', 1);
        $this->assertDatabaseCount('cash_transactions', 0);
        $this->assertSame([50000, 1750, -51750, 0], [$this->balance('PENDING'), $this->balance('PLATFORM'), $this->balance('CLEARING'), $this->balance('AVAILABLE')]);
    }

    public function test_unknown_or_mismatched_callbacks_and_wrong_host_are_rejected(): void
    {
        $p = $this->create();
        $this->post($this->webhook, $this->notice($p, ['reference_id' => 'BD-'.Str::ulid()]))->assertNotFound();
        $this->post($this->webhook, $this->notice($p, ['reference_id' => 'not-an-order']))->assertUnprocessable();
        $this->post($this->webhook, $this->notice($p, ['trx_id' => '123']))->assertUnprocessable();
        $this->post('http://financeqa.localhost/webhooks/ipaymu', $this->notice($p))->assertNotFound();
        $this->call('POST', $this->webhook, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->notice($p) + ['oversized' => str_repeat('x', 33000)]))->assertStatus(413);
        $this->assertDatabaseCount('payment_webhook_receipts', 0);
        $this->assertDatabaseCount('bill_settlements', 0);
    }

    public function test_provider_status_is_authoritative_and_receipts_retry_after_failure(): void
    {
        $p = $this->create();
        // Callback mengaku "berhasil", tetapi API iPaymu masih menunggu: tidak dikreditkan.
        $id = app(PaymentManager::class)->receive($this->notice($p));
        $this->ipaymu->timeout = true;
        app(PaymentManager::class)->processReceipt($id);
        $this->assertDatabaseHas('payment_webhook_receipts', ['id' => $id, 'status' => 'RETRY']);
        $this->ipaymu->timeout = false;
        app(PaymentManager::class)->processReceipt($id);
        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'PENDING']);
        $this->assertDatabaseCount('bill_settlements', 0);
    }

    public function test_paid_is_monotonic_and_unknown_status_is_blocked(): void
    {
        $p = $this->paid();
        foreach ([0, -2] as $status) {
            $this->ipaymu->statuses[$p->order_id] = $status;
            app(PaymentManager::class)->sync($p->id);
        }
        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'PAID']);
        $this->bill = DB::table('bills')->where('status', 'UNPAID')->value('id');
        $q = $this->create();
        $this->ipaymu->statuses[$q->order_id] = 99;
        app(PaymentManager::class)->sync($q->id);
        $this->assertDatabaseHas('payments', ['id' => $q->id, 'blocked' => true]);
        $this->assertDatabaseCount('bill_settlements', 1);
    }

    public function test_amount_reference_and_transaction_mismatches_block_credit(): void
    {
        foreach (['Amount' => 51749, 'ReferenceId' => 'BD-OTHER', 'TransactionId' => 123] as $field => $value) {
            $p = $this->create();
            $this->ipaymu->statuses[$p->order_id] = 1;
            $this->ipaymu->overrides = [$field => $value];
            app(PaymentManager::class)->sync($p->id);
            $this->assertDatabaseHas('payments', ['id' => $p->id, 'blocked' => true]);
        }
        $this->assertDatabaseCount('bill_settlements', 0);
        $this->assertSame(0, $this->balance('PENDING'));
    }

    public function test_manual_payment_wins_then_gateway_money_goes_to_exception(): void
    {
        $p = $this->create();
        app(FinanceManager::class)->settle($this->actor, $this->bill, ['cash_account_id' => $this->fixture['account'], 'submission_key' => (string) Str::uuid(), 'transaction_date' => now('Asia/Makassar')->toDateString(), 'reference' => 'CASH-TEST']);
        $this->ipaymu->statuses[$p->order_id] = 1;
        app(PaymentManager::class)->sync($p->id);
        $this->assertSame([50000, 0, 1750], [$this->balance('EXCEPTION'), $this->balance('PENDING'), $this->balance('PLATFORM')]);
        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertDatabaseHas('reconciliation_items', ['payment_id' => $p->id, 'code' => 'SETTLEMENT_CONFLICT']);
    }

    public function test_two_attempts_paid_only_one_settles_bill_and_second_is_exception(): void
    {
        $p = $this->create();
        $this->ipaymu->statuses[$p->order_id] = -2;
        app(PaymentManager::class)->sync($p->id);
        $q = $this->paid();
        $this->assertNotSame($p->id, $q->id);
        $this->ipaymu->statuses[$p->order_id] = 1;
        app(PaymentManager::class)->sync($p->id);
        $this->assertSame([50000, 50000], [$this->balance('PENDING'), $this->balance('EXCEPTION')]);
        $this->assertDatabaseCount('bill_settlements', 1);
    }

    public function test_bill_amount_is_held_then_released_only_after_a_fresh_paid_status(): void
    {
        $p = $this->paid();
        app(SettlementManager::class)->release($p->id);
        $this->assertSame(0, $this->balance('AVAILABLE'), 'Masih dalam masa tahan 2 hari.');
        $this->travel(3)->days();
        $this->ipaymu->overrides = ['Amount' => 1];
        app(SettlementManager::class)->release($p->id);
        $this->assertSame(0, $this->balance('AVAILABLE'), 'Status terbaru tidak cocok: tetap ditahan.');
        $this->ipaymu->overrides = [];
        DB::table('payments')->where('id', $p->id)->update(['blocked' => false]);
        DB::table('reconciliation_items')->delete();
        app(SettlementManager::class)->release($p->id);
        app(SettlementManager::class)->release($p->id);
        $this->assertSame([50000, 0], [$this->balance('AVAILABLE'), $this->balance('PENDING')]);
        $this->assertDatabaseCount('financial_ledger_transactions', 2);
        $this->travelBack();
    }

    public function test_unconfigured_hold_remains_pending_and_refund_quarantines_available(): void
    {
        config(['payment.settlement_hold_days' => null]);
        $p = $this->paid();
        $this->travel(30)->days();
        app(SettlementManager::class)->release($p->id);
        $this->assertSame(0, $this->balance('AVAILABLE'));
        $this->travelBack();
        config(['payment.settlement_hold_days' => 0]);
        $this->bill = DB::table('bills')->where('status', 'UNPAID')->value('id');
        $q = $this->paid();
        app(SettlementManager::class)->release($q->id);
        $this->assertSame(50000, $this->balance('AVAILABLE'));
        $this->ipaymu->statuses[$q->order_id] = 3;
        app(PaymentManager::class)->sync($q->id);
        app(PaymentManager::class)->sync($q->id);
        $this->assertSame([0, 50000], [$this->balance('AVAILABLE'), $this->balance('EXCEPTION')]);
    }

    public function test_system_callback_survives_suspension_and_module_disable(): void
    {
        $p = $this->create();
        DB::table('banjars')->where('id', $p->banjar_id)->update(['status' => 'SUSPENDED']);
        DB::table('banjar_modules')->where('banjar_id', $p->banjar_id)->where('code', 'PAYMENT')->update(['status' => 'INACTIVE']);
        $this->ipaymu->statuses[$p->order_id] = 1;
        app(PaymentManager::class)->processReceipt(app(PaymentManager::class)->receive($this->notice($p)));
        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'PAID']);
        $this->assertSame(50000, $this->balance('PENDING'));
    }

    public function test_warga_sees_fee_breakdown_and_qr_and_access_is_isolated(): void
    {
        $p = $this->create();
        $krama = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $p->banjar_id, 'user_id' => $krama->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $p->banjar_id, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'KRAMA')->value('id'), 'scope_type' => 'BANJAR']);
        DB::table('krama')->where('id', $this->fixture['people'][0])->update(['user_id' => $krama->id]);
        $host = 'http://financeqa.localhost';
        $this->tenantSession($krama)->get($host.'/payments')->assertOk()->assertSee('KK-QA-001')->assertDontSee('KK-QA-002');
        $this->tenantSession($krama)->get($host.'/payments/'.$p->id)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertSeeInOrder(['Nilai tagihan', 'Rp 50.000', 'Biaya layanan QRIS', 'Rp 1.750', 'Total dibayar', 'Rp 51.750'])
            ->assertSee('data-qris="'.$p->qr_string.'"', false);
        $other = DB::table('bills')->where('id', '<>', $this->bill)->value('id');
        $this->tenantSession($krama)->get($host.'/payments')->assertSee('biaya layanan Rp 1.750')->assertSee('Rp 51.750')->assertSee('Bayar tunai ke Petengen tanpa biaya');
        $this->tenantSession($krama)->post($host.'/payments/bills/'.$other)->assertNotFound();
        $this->tenantSession($krama)->get($host.'/admin/payments')->assertForbidden();
        $this->tenantSession()->get($host.'/admin/payments')->assertOk()->assertDontSee('Biaya gateway');
        $this->context();
        // QRIS hanya dari portal warga: Petengen tidak bisa membuat pembayaran QRIS atas nama warga.
        $this->rejected(fn () => app(PaymentManager::class)->create($this->actor, $other), 403);
        $super = $this->platformUser();
        $b = app(TenantManager::class)->create($super, ['name' => 'Other Banjar', 'slug' => 'payment-other', 'admin_name' => 'Other', 'admin_email' => 'other-payment@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($b));
        $this->rejected(fn () => app(PaymentManager::class)->visible($this->actor, $p->id), 404);
        $this->context();
        DB::table('banjar_modules')->where('banjar_id', $p->banjar_id)->where('code', 'PAYMENT')->update(['status' => 'INACTIVE']);
        $this->tenantSession()->get($host.'/payments')->assertForbidden();
    }

    public function test_platform_sees_service_revenue(): void
    {
        $this->ipaymu->fee = 500;
        $p = $this->paid();
        $super = $this->platformUser();
        $this->actingAs($super, 'platform')->withSession(['auth_host' => 'admin.banjar.localhost', 'auth_context' => 'platform'])->get('http://admin.banjar.localhost/platform/payments')->assertOk()
            ->assertSee('Pendapatan layanan QRIS')->assertSee('Rp 1.250')->assertSee('Rp 500');
        $this->actingAs($super, 'platform')->withSession(['auth_host' => 'admin.banjar.localhost', 'auth_context' => 'platform'])->get('http://admin.banjar.localhost/platform/payments/'.$p->id)->assertOk()
            ->assertSeeInOrder(['Dibayar warga', 'Rp 51.750', 'Untuk Banjar', 'Rp 50.000', 'Biaya layanan', 'Rp 1.750', 'Biaya iPaymu', 'Rp 500', 'Pendapatan platform', 'Rp 1.250']);
    }

    public function test_reconciliation_command_processes_inbox_and_detects_local_mismatch(): void
    {
        $p = $this->create();
        $this->ipaymu->statuses[$p->order_id] = 1;
        app(PaymentManager::class)->receive($this->notice($p));
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'PAID']);
        DB::table('bills')->where('id', $p->bill_id)->update(['status' => 'UNPAID']);
        DB::table('payments')->where('id', $p->id)->update(['next_check_at' => now()->subMinute()]);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('reconciliation_items', ['payment_id' => $p->id, 'code' => 'LOCAL_INTEGRITY']);
    }
}

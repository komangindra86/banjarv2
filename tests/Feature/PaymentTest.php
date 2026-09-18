<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Payment\LedgerService;
use App\Modules\Payment\MidtransPaymentGateway;
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
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private User $actor;

    private int $bill;

    private array $statuses = [];

    private array $overrides = [];

    private bool $timeout = false;

    private bool $earlyCallback = false;

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
        config(['payment.enabled' => true, 'payment.server_key' => 'test-server-key', 'payment.merchant_id' => 'TEST-MERCHANT', 'payment.production' => false, 'payment.settlement_hold_days' => 2]);
        $this->fixture = FinanceFixture::create();
        DB::table('banjar_modules')->where('banjar_id', $this->fixture['tenant'])->where('code', 'PAYMENT')->update(['status' => 'ACTIVE']);
        $this->actor = User::findOrFail($this->fixture['users']['PETENGEN']);
        app(FinanceManager::class)->issue(User::findOrFail($this->fixture['users']['KESINOMAN']), $this->fixture['event']);
        $this->bill = DB::table('bills')->orderBy('id')->value('id');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->timeout) {
                throw new \RuntimeException('Simulated timeout');
            }
            if ($request->method() === 'POST') {
                $order = $request['transaction_details']['order_id'];
                $payment = DB::table('payments')->where('order_id', $order)->first();
                if ($this->earlyCallback) {
                    $this->statuses[$order] = 'settlement';
                    app(PaymentManager::class)->sync($payment->id);
                }

                return Http::response($this->payload($payment, 'pending') + ['actions' => [['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/transaction-'.$payment->id.'/qr-code']]], 201);
            }
            $order = basename(dirname(parse_url($request->url(), PHP_URL_PATH)));
            $payment = DB::table('payments')->where('order_id', $order)->firstOrFail();

            return Http::response(array_replace($this->payload($payment, $this->statuses[$order] ?? 'pending'), $this->overrides), 200);
        });
    }

    private function context(): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->fixture['tenant']));
    }

    private function create(): object
    {
        $this->context();
        $id = app(PaymentManager::class)->create($this->actor, $this->bill);

        return DB::table('payments')->find($id);
    }

    private function payload(object $payment, string $status = 'settlement'): array
    {
        $data = ['order_id' => $payment->order_id, 'transaction_id' => 'transaction-'.$payment->id, 'gross_amount' => $payment->gross_amount.'.00',
            'merchant_id' => 'TEST-MERCHANT', 'payment_type' => 'qris', 'currency' => 'IDR', 'transaction_status' => $status, 'status_code' => '200', 'fraud_status' => 'accept'];
        $data['signature_key'] = hash('sha512', $data['order_id'].$data['status_code'].$data['gross_amount'].'test-server-key');

        return $data;
    }

    private function paid(): object
    {
        $p = $this->create();
        $this->statuses[$p->order_id] = 'settlement';
        app(PaymentManager::class)->sync($p->id);

        return DB::table('payments')->find($p->id);
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

    private function evidence(object $p): void
    {
        app(SettlementManager::class)->submit($this->platformUser(), $p->id, ['reference' => 'Laporan settlement bank TEST-2026', 'gateway_fee' => 350, 'settled_at' => now('Asia/Makassar')->format('Y-m-d\TH:i')]);
        app(SettlementManager::class)->approve($this->platformUser(), $p->id);
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

    public function test_charge_uses_server_amount_and_reuses_active_attempt(): void
    {
        $p = $this->create();
        $this->assertSame('PENDING', $p->status);
        $this->assertSame($p->id, $this->create()->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['transaction_details']['gross_amount'] === 50000 && ! isset($r['customer_details']));
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, $this->balance('PENDING'));
    }

    public function test_timeout_is_unknown_and_never_recharges_even_after_status_404(): void
    {
        $this->timeout = true;
        $p = $this->create();
        $this->assertSame('UNKNOWN', $p->status);
        $this->timeout = false;
        $this->assertSame($p->id, $this->create()->id);
        Http::fake(['*' => Http::response(['status_code' => '404'], 404)]);
        try {
            app(PaymentManager::class)->sync($p->id);
        } catch (\RuntimeException) {
        }
        $this->assertSame($p->id, $this->create()->id);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_callback_before_charge_response_cannot_downgrade_paid(): void
    {
        $this->earlyCallback = true;
        $p = $this->create();
        $this->assertSame('PAID', $p->status);
        $this->assertSame(50000, $this->balance('PENDING'));
        $this->assertDatabaseCount('bill_settlements', 1);
    }

    public function test_duplicate_webhooks_are_durable_redacted_and_credit_once(): void
    {
        Queue::fake();
        $p = $this->create();
        $this->statuses[$p->order_id] = 'settlement';
        $payload = $this->payload($p) + ['customer_name' => 'PRIVATE-PERSON'];
        $url = 'http://admin.banjar.localhost/webhooks/midtrans';
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('payment_webhook_receipts', 1);
        $receipt = DB::table('payment_webhook_receipts')->first();
        $this->assertStringNotContainsString('PRIVATE', $receipt->payload);
        $this->assertStringNotContainsString('signature', $receipt->payload);
        Queue::assertPushed(ProcessPaymentReceipt::class);
        app(PaymentManager::class)->processReceipt($receipt->id);
        app(PaymentManager::class)->processReceipt($receipt->id);
        app(PaymentManager::class)->sync($p->id);
        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertDatabaseCount('financial_ledger_transactions', 1);
        $this->assertDatabaseCount('cash_transactions', 0);
        $this->assertSame(50000, $this->balance('PENDING'));
        $this->assertSame(-50000, $this->balance('CLEARING'));
        $this->assertSame(0, $this->balance('AVAILABLE'));
    }

    public function test_invalid_signature_unknown_order_amount_and_host_are_rejected(): void
    {
        $p = $this->create();
        $url = 'http://admin.banjar.localhost/webhooks/midtrans';
        $this->postJson($url, array_replace($this->payload($p), ['signature_key' => 'bad']))->assertForbidden();
        $wrong = clone $p;
        $wrong->gross_amount = 1;
        $this->postJson($url, $this->payload($wrong))->assertUnprocessable();
        $wrong->order_id = 'unknown-order';
        $this->postJson($url, $this->payload($wrong))->assertNotFound();
        $this->postJson('http://financeqa.localhost/webhooks/midtrans', $this->payload($p))->assertNotFound();
        $this->postJson($url, $this->payload($p) + ['oversized' => str_repeat('x', 33000)])->assertStatus(413);
        $this->assertDatabaseCount('payment_webhook_receipts', 0);
        $this->assertDatabaseCount('bill_settlements', 0);
    }

    public function test_provider_status_is_authoritative_and_receipts_retry_after_failure(): void
    {
        $p = $this->create();
        $id = app(PaymentManager::class)->receive($this->payload($p));
        $this->timeout = true;
        app(PaymentManager::class)->processReceipt($id);
        $this->assertDatabaseHas('payment_webhook_receipts', ['id' => $id, 'status' => 'RETRY']);
        $this->timeout = false;
        app(PaymentManager::class)->processReceipt($id);
        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'PENDING']);
        $this->assertDatabaseCount('bill_settlements', 0);
    }

    public function test_paid_is_monotonic_and_generic_capture_is_not_qris_success(): void
    {
        $p = $this->paid();
        $this->statuses[$p->order_id] = 'pending';
        app(PaymentManager::class)->sync($p->id);
        $this->statuses[$p->order_id] = 'expire';
        app(PaymentManager::class)->sync($p->id);
        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'PAID']);
        $this->bill = DB::table('bills')->where('status', 'UNPAID')->value('id');
        $q = $this->create();
        $this->statuses[$q->order_id] = 'capture';
        app(PaymentManager::class)->sync($q->id);
        $this->assertDatabaseHas('payments', ['id' => $q->id, 'blocked' => true]);
        $this->assertDatabaseCount('bill_settlements', 1);
    }

    public function test_amount_merchant_type_and_transaction_mismatches_block_credit(): void
    {
        foreach (['gross_amount' => '50001.00', 'merchant_id' => 'OTHER', 'payment_type' => 'gopay', 'transaction_id' => 'different'] as $field => $value) {
            $p = $this->create();
            $this->statuses[$p->order_id] = 'settlement';
            $this->overrides = [$field => $value];
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
        $this->statuses[$p->order_id] = 'settlement';
        app(PaymentManager::class)->sync($p->id);
        $this->assertSame(50000, $this->balance('EXCEPTION'));
        $this->assertSame(0, $this->balance('PENDING'));
        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertDatabaseHas('reconciliation_items', ['payment_id' => $p->id, 'code' => 'SETTLEMENT_CONFLICT']);
    }

    public function test_two_attempts_paid_only_one_settles_bill_and_second_is_exception(): void
    {
        $p = $this->create();
        $this->statuses[$p->order_id] = 'expire';
        app(PaymentManager::class)->sync($p->id);
        $q = $this->paid();
        $this->assertNotSame($p->id, $q->id);
        $this->statuses[$p->order_id] = 'settlement';
        app(PaymentManager::class)->sync($p->id);
        $this->assertSame(50000, $this->balance('PENDING'));
        $this->assertSame(50000, $this->balance('EXCEPTION'));
        $this->assertDatabaseCount('bill_settlements', 1);
    }

    public function test_availability_requires_evidence_fee_second_approver_hold_and_fresh_status(): void
    {
        $p = $this->paid();
        app(SettlementManager::class)->release($p->id);
        $this->assertSame(0, $this->balance('AVAILABLE'));
        $submitter = $this->platformUser();
        app(SettlementManager::class)->submit($submitter, $p->id, ['reference' => 'Bank report confirmed receipt', 'gateway_fee' => 350, 'settled_at' => now('Asia/Makassar')->format('Y-m-d\TH:i')]);
        $this->rejected(fn () => app(SettlementManager::class)->approve($submitter, $p->id), 403);
        app(SettlementManager::class)->approve($this->platformUser(), $p->id);
        $this->assertSame(49650, $this->balance('PENDING'));
        $this->assertSame(350, $this->balance('FEE'));
        app(SettlementManager::class)->release($p->id);
        $this->assertSame(0, $this->balance('AVAILABLE'));
        $this->travel(3)->days();
        $this->statuses[$p->order_id] = 'pending';
        app(SettlementManager::class)->release($p->id);
        $this->assertSame(0, $this->balance('AVAILABLE'));
        $this->statuses[$p->order_id] = 'settlement';
        app(SettlementManager::class)->release($p->id);
        app(SettlementManager::class)->release($p->id);
        $this->assertSame(49650, $this->balance('AVAILABLE'));
        $this->assertSame(0, $this->balance('PENDING'));
        $this->assertDatabaseCount('financial_ledger_transactions', 3);
        $this->travelBack();
    }

    public function test_unconfigured_hold_remains_pending_and_refund_quarantines_available(): void
    {
        config(['payment.settlement_hold_days' => null]);
        $p = $this->paid();
        $this->rejected(fn () => $this->evidence($p), 409);
        config(['payment.settlement_hold_days' => 0]);
        $this->bill = DB::table('bills')->where('status', 'UNPAID')->value('id');
        $q = $this->paid();
        $this->evidence($q);
        app(SettlementManager::class)->release($q->id);
        $this->assertSame(49650, $this->balance('AVAILABLE'));
        $this->statuses[$q->order_id] = 'refund';
        app(PaymentManager::class)->sync($q->id);
        app(PaymentManager::class)->sync($q->id);
        $this->assertSame(0, $this->balance('AVAILABLE'));
        $this->assertSame(49650, $this->balance('EXCEPTION'));
    }

    public function test_system_callback_survives_suspension_and_module_disable(): void
    {
        $p = $this->create();
        DB::table('banjars')->where('id', $p->banjar_id)->update(['status' => 'SUSPENDED']);
        DB::table('banjar_modules')->where('banjar_id', $p->banjar_id)->where('code', 'PAYMENT')->update(['status' => 'INACTIVE']);
        $this->statuses[$p->order_id] = 'settlement';
        app(PaymentManager::class)->processReceipt(app(PaymentManager::class)->receive($this->payload($p)));
        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'PAID']);
        $this->assertSame(50000, $this->balance('PENDING'));
    }

    public function test_authorization_family_ownership_tenant_isolation_and_private_pages(): void
    {
        $p = $this->create();
        $krama = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $p->banjar_id, 'user_id' => $krama->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $p->banjar_id, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'KRAMA')->value('id'), 'scope_type' => 'BANJAR']);
        DB::table('krama')->where('id', $this->fixture['people'][0])->update(['user_id' => $krama->id]);
        $host = 'http://financeqa.localhost';
        $this->tenantSession($krama)->get($host.'/payments')->assertOk()->assertSee('KK-QA-001')->assertDontSee('KK-QA-002');
        $this->tenantSession($krama)->get($host.'/payments/'.$p->id)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $other = DB::table('bills')->where('id', '<>', $this->bill)->value('id');
        $this->tenantSession($krama)->post($host.'/payments/bills/'.$other)->assertNotFound();
        $this->tenantSession($krama)->get($host.'/admin/payments')->assertForbidden();
        $this->tenantSession()->get($host.'/admin/payments')->assertOk();
        $this->context();
        $this->rejected(fn () => app(SettlementManager::class)->submit($this->actor, $p->id, []), 403);
        $super = $this->platformUser();
        $b = app(TenantManager::class)->create($super, ['name' => 'Other Banjar', 'slug' => 'payment-other', 'admin_name' => 'Other', 'admin_email' => 'other-payment@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($b));
        $this->rejected(fn () => app(PaymentManager::class)->visible($this->actor, $p->id), 404);
        $this->context();
        DB::table('banjar_modules')->where('banjar_id', $p->banjar_id)->where('code', 'PAYMENT')->update(['status' => 'INACTIVE']);
        $this->tenantSession()->get($host.'/payments')->assertForbidden();
    }

    public function test_qr_url_and_signature_validation_are_strict(): void
    {
        $p = $this->create();
        $gateway = app(MidtransPaymentGateway::class);
        $this->assertTrue($gateway->verifyNotification($this->payload($p)));
        $this->assertFalse($gateway->verifyNotification(['order_id' => []]));
        foreach (['https://evil.test/v2/qris/id/qr-code', 'http://api.sandbox.midtrans.com/v2/qris/id/qr-code', 'https://user@api.sandbox.midtrans.com/v2/qris/id/qr-code', 'https://api.sandbox.midtrans.com/v2/qris/id/qr-code?token=x'] as $url) {
            $this->assertFalse(PaymentManager::safeQrUrl($url, false));
        }
        $this->assertTrue(PaymentManager::safeQrUrl($p->qr_url, false));
    }

    public function test_reconciliation_command_processes_inbox_and_detects_local_mismatch(): void
    {
        $p = $this->create();
        $this->statuses[$p->order_id] = 'settlement';
        app(PaymentManager::class)->receive($this->payload($p));
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'PAID']);
        DB::table('bills')->where('id', $p->bill_id)->update(['status' => 'UNPAID']);
        DB::table('payments')->where('id', $p->id)->update(['next_check_at' => now()->subMinute()]);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('reconciliation_items', ['payment_id' => $p->id, 'code' => 'LOCAL_INTEGRITY']);
    }
}

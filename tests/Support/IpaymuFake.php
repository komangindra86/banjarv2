<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tiruan API iPaymu v2 untuk test: /payment/direct (membuat QRIS) dan /transaction (status). Status per order
 * memakai kode iPaymu (0 menunggu, 1 berhasil, -2 kedaluwarsa, 3 refund, ...). TransactionId = 900000 + id pembayaran.
 */
final class IpaymuFake
{
    public array $statuses = [];

    public array $overrides = [];

    public bool $timeout = false;

    public int $fee = 0;

    public int $defaultStatus = 0;

    public ?Closure $onCharge = null;

    public const VA = '0000001234567890';

    public const KEY = 'ipaymu-test-key';

    public static function configure(array $extra = []): void
    {
        config($extra + ['payment.enabled' => true, 'payment.ipaymu.va' => self::VA, 'payment.ipaymu.api_key' => self::KEY, 'payment.ipaymu.sandbox' => true,
            'payment.service_fee_percent' => 3.5, 'payment.settlement_hold_days' => 2]);
    }

    public static function transactionId(object $payment): string
    {
        return (string) (900000 + $payment->id);
    }

    public function install(): self
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->timeout) {
                throw new RuntimeException('Simulated timeout');
            }
            $body = json_decode($request->body(), true);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/payment/direct')) {
                $payment = DB::table('payments')->where('order_id', $body['referenceId'])->firstOrFail();
                if ($this->onCharge) {
                    ($this->onCharge)($payment, self::transactionId($payment));
                }

                return Http::response(['Status' => 200, 'Success' => true, 'Message' => 'Success', 'Data' => ['SessionId' => 'session-'.$payment->id,
                    'TransactionId' => (int) self::transactionId($payment), 'ReferenceId' => $payment->order_id, 'QrString' => '00020101021226670016COM.IPAYMU.WWW-'.$payment->id,
                    'Total' => $body['amount'], 'Fee' => $this->fee]]);
            }
            if (str_ends_with($path, '/transaction')) {
                $payment = DB::table('payments')->find((int) $body['transactionId'] - 900000);
                if (! $payment) {
                    return Http::response(['Status' => 404, 'Message' => 'Not found'], 404);
                }

                return Http::response(['Status' => 200, 'Data' => array_replace(['TransactionId' => (int) $body['transactionId'], 'ReferenceId' => $payment->order_id,
                    'Status' => $this->statuses[$payment->order_id] ?? $this->defaultStatus, 'Amount' => (int) $payment->gross_amount, 'Fee' => $this->fee], $this->overrides)]);
            }

            return Http::response(['Status' => 404], 404);
        });

        return $this;
    }
}

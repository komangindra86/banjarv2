<?php

namespace App\Modules\Payment;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PaymentManager
{
    public const STATUSES = ['CREATING' => 'Menyiapkan QRIS', 'UNKNOWN' => 'Sedang diperiksa', 'PENDING' => 'Menunggu pembayaran', 'PAID' => 'Pembayaran diterima', 'EXPIRED' => 'QRIS kedaluwarsa', 'DENIED' => 'Pembayaran ditolak', 'CANCELLED' => 'Dibatalkan', 'EXCEPTION' => 'Perlu pemeriksaan'];

    public function __construct(private PaymentAccess $access, private CurrentBanjar $tenant, private PaymentGatewayInterface $gateway, private LedgerService $ledger, private Audit $audit) {}

    public static function ready(): bool
    {
        return (bool) config('payment.enabled') && filled(config('payment.server_key')) && filled(config('payment.merchant_id'));
    }

    public function create(User $actor, int $billId): int
    {
        abort_unless(self::ready(), 503, 'QRIS belum diaktifkan oleh Bali Santih.');
        $this->access->bill($actor, $billId, true);
        [$id, $created] = DB::transaction(function () use ($actor, $billId) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->firstOrFail();
            $bill = $this->access->bill($actor, $billId, true);
            abort_unless($bill->status === 'UNPAID', 409, 'Tagihan sudah lunas atau dibatalkan.');
            $base = ['banjar_id' => $bill->banjar_id, 'created_at' => now(), 'updated_at' => now()];
            DB::table('payment_attempt_locks')->insertOrIgnore($base + ['bill_id' => $billId]);
            $guard = DB::table('payment_attempt_locks')->where('banjar_id', $bill->banjar_id)->where('bill_id', $billId)->first();
            if ($guard->active_payment_id) {
                $previous = DB::table('payments')->find($guard->active_payment_id);
                if (! in_array($previous->status, ['EXPIRED', 'DENIED', 'CANCELLED']) || $previous->blocked) {
                    return [$previous->id, false];
                }
            }
            $hold = config('payment.settlement_hold_days');
            abort_unless($hold === null || (ctype_digit((string) $hold) && (int) $hold <= 365), 503);
            $id = DB::table('payments')->insertGetId($base + ['bill_id' => $billId, 'order_id' => 'BD-'.Str::ulid(),
                'merchant_id' => config('payment.merchant_id'), 'production' => (bool) config('payment.production'),
                'gross_amount' => $bill->gross_amount, 'hold_days' => $hold, 'created_by' => $actor->id, 'next_check_at' => now()->addMinutes(2)]);
            DB::table('payment_attempt_locks')->where('id', $guard->id)->update(['active_payment_id' => $id, 'updated_at' => now()]);
            $this->audit->record($bill->banjar_id, $actor->id, 'payment.created', 'payments', $id, [], ['bill_id' => $billId, 'gross_amount' => $bill->gross_amount]);

            return [$id, true];
        }, 3);
        if (! $created) {
            return $id;
        }
        $payment = DB::table('payments')->find($id);
        try {
            $result = $this->gateway->createQrisPayment($payment);
            // Charge responses may supply a QR, but only server status can settle a bill.
            $this->validateProvider($payment, $result);
            $url = collect($result['actions'] ?? [])->firstWhere('name', 'generate-qr-code')['url'] ?? null;
            DB::transaction(function () use ($payment, $result, $url) {
                DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
                $fresh = DB::table('payments')->find($payment->id);
                $this->validateProvider($fresh, $result);
                if (in_array($fresh->status, ['CREATING', 'UNKNOWN'])) {
                    DB::table('payments')->where('id', $fresh->id)->update(['midtrans_transaction_id' => $result['transaction_id'], 'qr_url' => self::safeQrUrl($url, (bool) $fresh->production) ? $url : null,
                        'status' => 'PENDING', 'updated_at' => now()]);
                }
            }, 3);
        } catch (Throwable) {
            // Do not expose gateway exceptions (which can contain credentials or payloads).
            DB::table('payments')->where('id', $id)->where('status', 'CREATING')->update(['status' => 'UNKNOWN', 'updated_at' => now()]);
        }

        return $id;
    }

    public static function safeQrUrl(mixed $url, bool $production): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $parts = parse_url($url);

        return $parts && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === ($production ? 'api.midtrans.com' : 'api.sandbox.midtrans.com')
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && preg_match('~^/v[24]/qris/[A-Za-z0-9-]+/qr-code$~', $parts['path'] ?? '') === 1;
    }

    private function validateProvider(object $payment, array $data): void
    {
        foreach (['order_id', 'gross_amount', 'merchant_id', 'transaction_id', 'payment_type', 'currency', 'transaction_status'] as $key) {
            abort_unless(isset($data[$key]) && is_string($data[$key]), 422, 'Respons provider tidak lengkap.');
        }
        abort_unless($data['order_id'] === $payment->order_id && $data['merchant_id'] === $payment->merchant_id
            && $data['payment_type'] === 'qris' && $data['currency'] === 'IDR'
            && preg_match('/^[0-9]{1,12}(?:\.00)?$/D', $data['gross_amount']) === 1
            && (int) $data['gross_amount'] === (int) $payment->gross_amount
            && preg_match('/^[A-Za-z0-9-]{1,100}$/D', $data['transaction_id']) === 1
            && (! $payment->midtrans_transaction_id || $payment->midtrans_transaction_id === $data['transaction_id']), 422, 'Identitas atau nominal provider tidak sesuai.');
        abort_if(DB::table('payments')->where('midtrans_transaction_id', $data['transaction_id'])->where('id', '<>', $payment->id)->exists(), 422);
    }

    public function refreshFor(User $actor, int $id): void
    {
        $payment = $this->visible($actor, $id);
        abort_if($payment->checked_at && now()->diffInSeconds($payment->checked_at, true) < 15, 429);
        $this->sync($id);
    }

    public function visible(User $actor, int $id): object
    {
        $payment = DB::table('payments')->where('banjar_id', $this->tenant->id())->where('id', $id)->first();
        abort_unless($payment, 404);
        $this->access->bill($actor, $payment->bill_id);

        return $payment;
    }

    // System entry: persisted payment selects tenant, even when tenant/module is suspended.
    public function sync(int $id): bool
    {
        $payment = DB::table('payments')->find($id);
        abort_unless($payment, 404);
        $data = $this->gateway->getStatus($payment);

        return DB::transaction(function () use ($payment, $data) {
            DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->find($payment->id);
            try {
                $this->validateProvider($payment, $data);
            } catch (Throwable) {
                $this->block($payment, 'PROVIDER_MISMATCH');

                return false;
            }
            $status = $data['transaction_status'];
            DB::table('payments')->where('id', $payment->id)->update(['checked_at' => now(), 'next_check_at' => now()->addMinutes(15), 'updated_at' => now()]);
            if (in_array($status, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback']) || (isset($data['fraud_status']) && $data['fraud_status'] !== 'accept')) {
                $this->block($payment, 'PROVIDER_REVIEW');

                return false;
            }
            if ($status === 'settlement') {
                if (! in_array((string) ($data['status_code'] ?? ''), ['200', '201', '202'])) {
                    $this->block($payment, 'PROVIDER_MISMATCH');

                    return false;
                }
                if ($payment->paid_at) {
                    return ! $payment->blocked && $payment->status === 'PAID';
                }
                $bill = DB::table('bills')->where('banjar_id', $payment->banjar_id)->where('id', $payment->bill_id)->firstOrFail();
                $conflict = $payment->blocked || $bill->status !== 'UNPAID' || DB::table('bill_settlements')->where('banjar_id', $payment->banjar_id)->where('bill_id', $bill->id)->whereNull('reversed_at')->exists();
                $this->ledger->post($payment, 'received', ['CLEARING' => (int) $payment->gross_amount], [$conflict ? 'EXCEPTION' : 'PENDING' => (int) $payment->gross_amount]);
                DB::table('payments')->where('id', $payment->id)->update(['midtrans_transaction_id' => $data['transaction_id'], 'status' => $conflict ? 'EXCEPTION' : 'PAID', 'paid_at' => now(), 'updated_at' => now()]);
                if ($conflict) {
                    $this->block($payment, 'SETTLEMENT_CONFLICT');
                } else {
                    DB::table('bill_settlements')->insert(['banjar_id' => $payment->banjar_id, 'bill_id' => $bill->id, 'method' => 'GATEWAY', 'amount' => $payment->gross_amount,
                        'payment_id' => $payment->id, 'created_by' => $payment->created_by, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('bills')->where('id', $bill->id)->update(['status' => 'PAID', 'updated_at' => now()]);
                }
                $this->audit->record($payment->banjar_id, null, 'payment.received', 'payments', $payment->id, [], ['status' => $conflict ? 'EXCEPTION' : 'PAID', 'gross_amount' => $payment->gross_amount]);

                return ! $conflict;
            } elseif (in_array($status, ['pending', 'expire', 'deny', 'cancel'])) {
                // A delayed pending/expiry must never undo verified money or revive a closed attempt.
                if (! $payment->paid_at && in_array($payment->status, ['CREATING', 'UNKNOWN', 'PENDING'])) {
                    DB::table('payments')->where('id', $payment->id)->update(['midtrans_transaction_id' => $data['transaction_id'],
                        'status' => ['pending' => 'PENDING', 'expire' => 'EXPIRED', 'deny' => 'DENIED', 'cancel' => 'CANCELLED'][$status], 'updated_at' => now()]);
                }
            } else {
                $this->block($payment, 'UNSUPPORTED_STATUS');
            }

            return false;
        }, 3);
    }

    public function flagIntegrity(int $id): void
    {
        $payment = DB::table('payments')->find($id);
        DB::transaction(function () use ($payment) {
            DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
            $this->block(DB::table('payments')->find($payment->id), 'LOCAL_INTEGRITY');
        }, 3);
    }

    private function block(object $payment, string $code): void
    {
        DB::table('payments')->where('id', $payment->id)->update(['blocked' => true, 'updated_at' => now()]);
        DB::table('reconciliation_items')->insertOrIgnore(['banjar_id' => $payment->banjar_id, 'payment_id' => $payment->id, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
        if ($payment->released_at && ! $payment->blocked) {
            // Availability is quarantined; no refund or destructive balance edit is performed.
            $this->ledger->post($payment, 'quarantine', ['AVAILABLE' => (int) $payment->net_amount], ['EXCEPTION' => (int) $payment->net_amount]);
        }
        $this->audit->record($payment->banjar_id, null, 'payment.blocked', 'payments', $payment->id);
    }

    public function receive(array $payload): int
    {
        abort_unless($this->gateway->verifyNotification($payload), 403);
        $payment = DB::table('payments')->where('order_id', $payload['order_id'])->first();
        abort_unless($payment, 404);
        $this->validateProvider($payment, $payload);
        // Only a small allowlist is persisted; signatures, PII and raw payload are discarded.
        $safe = array_intersect_key($payload, array_flip(['order_id', 'transaction_id', 'transaction_status', 'status_code', 'gross_amount', 'merchant_id', 'currency', 'payment_type']));
        ksort($safe);
        $json = json_encode($safe, JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $json);
        DB::table('payment_webhook_receipts')->insertOrIgnore(['banjar_id' => $payment->banjar_id, 'payment_id' => $payment->id, 'fingerprint' => $fingerprint, 'payload' => $json, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('payment_webhook_receipts')->where('fingerprint', $fingerprint)->value('id');
    }

    public function processReceipt(int $id): void
    {
        $receipt = DB::table('payment_webhook_receipts')->find($id);
        if (! $receipt || $receipt->status === 'PROCESSED') {
            return;
        }
        DB::table('payment_webhook_receipts')->where('id', $id)->increment('attempts');
        try {
            $this->sync($receipt->payment_id);
            DB::table('payment_webhook_receipts')->where('id', $id)->update(['status' => 'PROCESSED', 'last_error' => null, 'updated_at' => now()]);
        } catch (Throwable) {
            DB::table('payment_webhook_receipts')->where('id', $id)->where('status', '<>', 'PROCESSED')->update(['status' => 'RETRY', 'last_error' => 'STATUS_UNAVAILABLE',
                'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min(7, $receipt->attempts)))), 'updated_at' => now()]);
        }
    }
}

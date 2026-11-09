<?php

namespace App\Modules\Payment;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * QRIS warga (§29, §43). Warga membayar nilai tagihan + biaya layanan (config payment.service_fee_percent). Saat lunas:
 * - nilai tagihan → kantong PENDING Banjar (dapat dicairkan setelah masa tahan, SettlementManager::release);
 * - biaya layanan → kantong PLATFORM (pendapatan Bali Santih), dikurangi biaya iPaymu (kantong FEE).
 * Status selalu dibaca dari server penyedia; callback hanya penanda untuk memeriksa ulang.
 */
final class PaymentManager
{
    public const STATUSES = ['CREATING' => 'Menyiapkan QRIS', 'UNKNOWN' => 'Sedang diperiksa', 'PENDING' => 'Menunggu pembayaran', 'PAID' => 'Pembayaran diterima', 'EXPIRED' => 'QRIS kedaluwarsa', 'DENIED' => 'Pembayaran gagal', 'CANCELLED' => 'Dibatalkan', 'EXCEPTION' => 'Perlu pemeriksaan pengurus'];

    public function __construct(private PaymentAccess $access, private CurrentBanjar $tenant, private PaymentGatewayInterface $gateway, private LedgerService $ledger, private Audit $audit) {}

    public static function ready(): bool
    {
        return (bool) config('payment.enabled') && app(PaymentGatewayInterface::class)::configured();
    }

    /** Biaya layanan QRIS untuk nilai tagihan tertentu, dibulatkan ke atas per rupiah. */
    public static function serviceFee(int $amount): int
    {
        $basisPoints = (int) round((float) config('payment.service_fee_percent') * 100);

        return $basisPoints > 0 ? intdiv($amount * $basisPoints + 9999, 10000) : 0;
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
            $fee = self::serviceFee((int) $bill->gross_amount);
            $id = DB::table('payments')->insertGetId($base + ['bill_id' => $billId, 'order_id' => 'BD-'.Str::ulid(), 'provider' => 'IPAYMU',
                'merchant_id' => $this->gateway::merchantId(), 'production' => $this->gateway::production(),
                'bill_amount' => $bill->gross_amount, 'service_fee' => $fee, 'gross_amount' => $bill->gross_amount + $fee,
                'hold_days' => $hold, 'created_by' => $actor->id, 'next_check_at' => now()->addMinutes(2)]);
            DB::table('payment_attempt_locks')->where('id', $guard->id)->update(['active_payment_id' => $id, 'updated_at' => now()]);
            $this->audit->record($bill->banjar_id, $actor->id, 'payment.created', 'payments', $id, [], ['bill_id' => $billId, 'gross_amount' => $bill->gross_amount + $fee, 'amount' => $bill->gross_amount]);

            return [$id, true];
        }, 3);
        if (! $created) {
            return $id;
        }
        $payment = DB::table('payments')->find($id);
        try {
            $result = $this->gateway->createQrisPayment($payment, $this->buyer($actor, $payment));
            abort_unless(self::validTransactionId($result['transaction_id'] ?? null) && is_string($result['qr_string'] ?? null) && strlen($result['qr_string']) <= 2000, 422);
            abort_if(DB::table('payments')->where('provider_transaction_id', $result['transaction_id'])->where('id', '<>', $payment->id)->exists(), 422);
            DB::transaction(function () use ($payment, $result) {
                DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
                $fresh = DB::table('payments')->find($payment->id);
                if (in_array($fresh->status, ['CREATING', 'UNKNOWN']) && (! $fresh->provider_transaction_id || $fresh->provider_transaction_id === $result['transaction_id'])) {
                    DB::table('payments')->where('id', $fresh->id)->update(['provider_transaction_id' => $result['transaction_id'], 'qr_string' => $result['qr_string'],
                        'status' => $fresh->paid_at ? $fresh->status : 'PENDING', 'updated_at' => now()]);
                }
            }, 3);
        } catch (Throwable) {
            // Jangan tampilkan galat penyedia (bisa memuat kredensial/isi permintaan). Tidak pernah dibuat ulang otomatis.
            DB::table('payments')->where('id', $id)->where('status', 'CREATING')->update(['status' => 'UNKNOWN', 'updated_at' => now()]);
        }

        return $id;
    }

    /** Rincian untuk iPaymu: nilai tagihan dan biaya layanan sebagai dua baris, berlabel Banjar & nomor tagihan. */
    private function buyer(User $actor, object $payment): array
    {
        $banjar = DB::table('banjars')->where('id', $payment->banjar_id)->value('name');
        $title = DB::table('bills as b')->join('billing_events as e', fn ($j) => $j->on('e.id', '=', 'b.billing_event_id')->on('e.banjar_id', '=', 'b.banjar_id'))
            ->where('b.id', $payment->bill_id)->value('e.title');
        $label = mb_substr(($title ?: 'Tagihan').' · '.$banjar.' (tagihan #'.$payment->bill_id.')', 0, 200);
        $phone = DB::table('krama')->where('banjar_id', $payment->banjar_id)->where('user_id', $actor->id)->value('phone');
        $phone = is_string($phone) ? preg_replace('/[^0-9]/', '', $phone) : '';
        $email = filter_var($actor->email, FILTER_VALIDATE_EMAIL) ? $actor->email : (string) config('payment.ipaymu.fallback_email');
        $items = [['name' => $label, 'price' => (int) $payment->bill_amount]];
        if ((int) $payment->service_fee > 0) {
            $items[] = ['name' => 'Biaya layanan QRIS', 'price' => (int) $payment->service_fee];
        }

        return ['label' => $label, 'items' => $items, 'name' => $actor->name ?: 'Warga '.$banjar,
            'phone' => strlen($phone) >= 8 ? $phone : (string) config('payment.ipaymu.fallback_phone'), 'email' => $email];
    }

    private static function validTransactionId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9-]{1,100}$/D', $id) === 1;
    }

    private function validateProvider(object $payment, array $data): void
    {
        abort_unless(self::validTransactionId($data['transaction_id'] ?? null) && ($data['reference_id'] ?? null) === $payment->order_id
            && is_int($data['amount'] ?? null) && $data['amount'] === (int) $payment->gross_amount
            && (! $payment->provider_transaction_id || $payment->provider_transaction_id === $data['transaction_id']), 422, 'Identitas atau nominal provider tidak sesuai.');
        abort_if(DB::table('payments')->where('provider_transaction_id', $data['transaction_id'])->where('id', '<>', $payment->id)->exists(), 422);
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
            $status = $data['status'];
            DB::table('payments')->where('id', $payment->id)->update(['checked_at' => now(), 'next_check_at' => now()->addMinutes(15), 'updated_at' => now()]);
            if ($status === 'refunded') {
                $this->block($payment, 'PROVIDER_REVIEW');

                return false;
            }
            if ($status === 'paid') {
                if ($payment->paid_at) {
                    return ! $payment->blocked && $payment->status === 'PAID';
                }
                $bill = DB::table('bills')->where('banjar_id', $payment->banjar_id)->where('id', $payment->bill_id)->firstOrFail();
                $conflict = $payment->blocked || $bill->status !== 'UNPAID' || DB::table('bill_settlements')->where('banjar_id', $payment->banjar_id)->where('bill_id', $bill->id)->whereNull('reversed_at')->exists();
                $billAmount = (int) $payment->bill_amount;
                $serviceFee = (int) $payment->service_fee;
                // Nilai tagihan milik Banjar; biaya layanan milik platform. Pembayaran ganda/konflik ditahan di EXCEPTION.
                $this->ledger->post($payment, 'received', ['CLEARING' => (int) $payment->gross_amount], array_filter([$conflict ? 'EXCEPTION' : 'PENDING' => $billAmount, 'PLATFORM' => $serviceFee]));
                $gatewayFee = $data['fee'];
                if (is_int($gatewayFee) && $gatewayFee > 0 && $gatewayFee <= $serviceFee) {
                    $this->ledger->post($payment, 'fee', ['PLATFORM' => $gatewayFee], ['FEE' => $gatewayFee]);
                }
                $hold = $payment->hold_days;
                DB::table('payments')->where('id', $payment->id)->update(['provider_transaction_id' => $data['transaction_id'], 'status' => $conflict ? 'EXCEPTION' : 'PAID', 'paid_at' => now(),
                    'gateway_fee' => $gatewayFee, 'platform_fee' => max(0, $serviceFee - (int) $gatewayFee), 'net_amount' => $billAmount,
                    'settlement_at' => now(), 'available_at' => $hold === null ? null : now()->addDays((int) $hold), 'updated_at' => now()]);
                if (is_int($gatewayFee) && $gatewayFee > $serviceFee) {
                    DB::table('reconciliation_items')->insertOrIgnore(['banjar_id' => $payment->banjar_id, 'payment_id' => $payment->id, 'code' => 'FEE_EXCEEDS_SERVICE', 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($conflict) {
                    $this->block($payment, 'SETTLEMENT_CONFLICT');
                } else {
                    DB::table('bill_settlements')->insert(['banjar_id' => $payment->banjar_id, 'bill_id' => $bill->id, 'method' => 'GATEWAY', 'amount' => $billAmount,
                        'payment_id' => $payment->id, 'created_by' => $payment->created_by, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('bills')->where('id', $bill->id)->update(['status' => 'PAID', 'updated_at' => now()]);
                }
                $this->audit->record($payment->banjar_id, null, 'payment.received', 'payments', $payment->id, [], ['status' => $conflict ? 'EXCEPTION' : 'PAID', 'gross_amount' => $payment->gross_amount, 'amount' => $billAmount]);

                return ! $conflict;
            } elseif (in_array($status, ['pending', 'expired', 'cancelled', 'failed'])) {
                // A delayed pending/expiry must never undo verified money or revive a closed attempt.
                if (! $payment->paid_at && in_array($payment->status, ['CREATING', 'UNKNOWN', 'PENDING'])) {
                    DB::table('payments')->where('id', $payment->id)->update(['provider_transaction_id' => $data['transaction_id'],
                        'status' => ['pending' => 'PENDING', 'expired' => 'EXPIRED', 'failed' => 'DENIED', 'cancelled' => 'CANCELLED'][$status], 'updated_at' => now()]);
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

    /**
     * Callback iPaymu (notifyUrl): hanya menunjuk transaksi mana yang perlu diperiksa ulang. Tidak ada tanda tangan,
     * jadi isinya tidak pernah dipercaya — status lunas selalu dibaca ulang dari API iPaymu (sync).
     */
    public function receive(array $payload): int
    {
        $reference = $payload['reference_id'] ?? null;
        $transaction = isset($payload['trx_id']) ? (string) $payload['trx_id'] : null;
        abort_unless(is_string($reference) && preg_match('/^BD-[0-9A-Z]{26}$/D', $reference) === 1 && self::validTransactionId($transaction), 422);
        $payment = DB::table('payments')->where('order_id', $reference)->first();
        abort_unless($payment, 404);
        abort_if($payment->provider_transaction_id && $payment->provider_transaction_id !== $transaction, 422);
        if (! $payment->provider_transaction_id) {
            // Pembuatan QRIS sempat timeout: nomor transaksi diambil dari callback, lalu diverifikasi lewat API saat sync.
            abort_if(DB::table('payments')->where('provider_transaction_id', $transaction)->exists(), 422);
            DB::table('payments')->where('id', $payment->id)->whereNull('provider_transaction_id')->update(['provider_transaction_id' => $transaction, 'updated_at' => now()]);
        }
        // Hanya sebagian kecil yang disimpan; data pembeli dan isi mentah dibuang.
        $safe = ['reference_id' => $reference, 'status' => is_scalar($payload['status'] ?? null) ? mb_substr((string) $payload['status'], 0, 30) : null, 'trx_id' => $transaction];
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

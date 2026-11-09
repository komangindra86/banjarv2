<?php

namespace App\Modules\Payment;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * QRIS warga (§29, §43). Satu QRIS dapat melunasi beberapa tagihan sekaligus (payment_items). Warga membayar total
 * tagihan + biaya layanan aplikasi bertingkat (serviceFee). Saat lunas:
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

    /** Tingkatan biaya layanan aplikasi dari konfigurasi: [[batas total, biaya], ...] urut naik. */
    public static function feeTiers(): array
    {
        $tiers = [];
        foreach (explode(',', (string) config('payment.service_fee_tiers')) as $pair) {
            if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/D', $pair, $m) === 1) {
                $tiers[] = [(int) $m[1], (int) $m[2]];
            }
        }
        usort($tiers, fn ($a, $b) => $a[0] <=> $b[0]);

        return $tiers;
    }

    /** Persen di atas batas tingkatan terakhir, dalam basis poin (1% = 100). */
    public static function feeAboveBasisPoints(): int
    {
        return max(0, (int) round((float) config('payment.service_fee_above_percent') * 100));
    }

    /**
     * Biaya layanan aplikasi untuk total tagihan yang dibayar dalam satu QRIS: tarif tetap per tingkatan, di atas batas
     * terakhir persen dari total (dibulatkan ke atas per rupiah, tidak kurang dari tarif tingkatan terakhir).
     * Rumus yang sama ada di resources/js/service-fee.js untuk pratinjau di halaman tagihan.
     */
    public static function serviceFee(int $total): int
    {
        if ($total <= 0) {
            return 0;
        }
        $tiers = self::feeTiers();
        foreach ($tiers as [$limit, $fee]) {
            if ($total <= $limit) {
                return $fee;
            }
        }
        $basisPoints = self::feeAboveBasisPoints();

        return max($tiers === [] ? 0 : end($tiers)[1], $basisPoints > 0 ? intdiv($total * $basisPoints + 9999, 10000) : 0);
    }

    /** @param  int|int[]  $billIds  satu atau beberapa tagihan yang dibayar dengan satu QRIS */
    public function create(User $actor, int|array $billIds): int
    {
        abort_unless(self::ready(), 503, 'QRIS belum diaktifkan oleh Bali Santih.');
        $billIds = array_values(array_unique(array_filter(array_map('intval', (array) $billIds), fn ($id) => $id > 0)));
        sort($billIds);
        abort_if($billIds === [], 422, 'Pilih tagihan yang akan dibayar.');
        abort_if(count($billIds) > (int) config('payment.max_bills', 50), 422, 'Terlalu banyak tagihan dalam satu QRIS.');
        abort_unless($this->access->bills($actor, true)->whereIn('id', $billIds)->count() === count($billIds), 404);
        [$id, $created] = DB::transaction(function () use ($actor, $billIds) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->firstOrFail();
            $bills = $this->access->bills($actor, true)->whereIn('id', $billIds)->orderBy('id')->get();
            abort_unless($bills->count() === count($billIds), 404);
            abort_unless($bills->every(fn ($bill) => $bill->status === 'UNPAID'), 409, 'Ada tagihan yang sudah lunas atau dibatalkan.');
            $banjar = $this->tenant->id();
            $base = ['banjar_id' => $banjar, 'created_at' => now(), 'updated_at' => now()];
            DB::table('payment_attempt_locks')->insertOrIgnore(array_map(fn ($billId) => $base + ['bill_id' => $billId], $billIds));
            $guards = DB::table('payment_attempt_locks')->where('banjar_id', $banjar)->whereIn('bill_id', $billIds)->get();
            // Tagihan yang masih terikat QRIS lain yang belum selesai tidak boleh dibayar dua kali.
            $live = DB::table('payments')->whereIn('id', $guards->pluck('active_payment_id')->filter())
                ->where(fn ($q) => $q->whereNotIn('status', ['EXPIRED', 'DENIED', 'CANCELLED'])->orWhere('blocked', true))->pluck('id');
            if ($live->isNotEmpty()) {
                $same = $live->count() === 1 && DB::table('payment_items')->where('payment_id', $live->first())->orderBy('bill_id')->pluck('bill_id')->map(fn ($v) => (int) $v)->all() === $billIds;
                abort_unless($same, 409, 'Sebagian tagihan sedang menunggu pembayaran QRIS lain. Selesaikan QRIS itu atau tunggu sampai kedaluwarsa.');

                return [$live->first(), false];
            }
            $hold = config('payment.settlement_hold_days');
            abort_unless($hold === null || (ctype_digit((string) $hold) && (int) $hold <= 365), 503);
            $total = (int) $bills->sum('gross_amount');
            $fee = self::serviceFee($total);
            $id = DB::table('payments')->insertGetId($base + ['order_id' => 'BD-'.Str::ulid(), 'provider' => 'IPAYMU',
                'merchant_id' => $this->gateway::merchantId(), 'production' => $this->gateway::production(),
                'bill_amount' => $total, 'service_fee' => $fee, 'gross_amount' => $total + $fee,
                'hold_days' => $hold, 'created_by' => $actor->id, 'next_check_at' => now()->addMinutes(2)]);
            DB::table('payment_items')->insert($bills->map(fn ($bill) => $base + ['payment_id' => $id, 'bill_id' => $bill->id, 'amount' => $bill->gross_amount])->all());
            DB::table('payment_attempt_locks')->where('banjar_id', $banjar)->whereIn('bill_id', $billIds)->update(['active_payment_id' => $id, 'updated_at' => now()]);
            $this->audit->record($banjar, $actor->id, 'payment.created', 'payments', $id, [], ['bill_ids' => $billIds, 'gross_amount' => $total + $fee, 'amount' => $total]);

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

    /** Rincian tagihan suatu pembayaran beserta judul event-nya. */
    public static function items(object $payment): Collection
    {
        return DB::table('payment_items as i')->join('bills as b', fn ($j) => $j->on('b.id', '=', 'i.bill_id')->on('b.banjar_id', '=', 'i.banjar_id'))
            ->leftJoin('billing_events as e', fn ($j) => $j->on('e.id', '=', 'b.billing_event_id')->on('e.banjar_id', '=', 'b.banjar_id'))
            ->where('i.payment_id', $payment->id)->orderBy('i.bill_id')
            ->get(['i.bill_id', 'i.amount', 'b.kind', 'b.household_code', 'b.status as bill_status', 'e.title']);
    }

    /** Semua tagihan pembayaran ini lunas oleh pembayaran ini sendiri, dengan nominal yang cocok. */
    public static function settlementsIntact(object $payment): bool
    {
        $items = DB::table('payment_items')->where('payment_id', $payment->id)->get();
        $settled = DB::table('bill_settlements')->where('payment_id', $payment->id)->whereNull('reversed_at')->get()->keyBy('bill_id');

        return $items->isNotEmpty() && $settled->count() === $items->count() && (int) $items->sum('amount') === (int) $payment->net_amount
            && $items->every(fn ($item) => (int) ($settled->get($item->bill_id)->amount ?? -1) === (int) $item->amount)
            && DB::table('bills')->whereIn('id', $items->pluck('bill_id'))->where('status', '<>', 'PAID')->doesntExist();
    }

    /** Rincian untuk iPaymu: satu baris per tagihan dan satu baris biaya layanan, berlabel Banjar & nomor tagihan. */
    private function buyer(User $actor, object $payment): array
    {
        $banjar = DB::table('banjars')->where('id', $payment->banjar_id)->value('name');
        $bills = self::items($payment);
        $numbers = '#'.$bills->pluck('bill_id')->implode(', #');
        $label = mb_substr(($bills->count() === 1 ? ($bills->first()->title ?: 'Tagihan') : $bills->count().' tagihan').' · '.$banjar.' (tagihan '.$numbers.')', 0, 200);
        $phone = DB::table('krama')->where('banjar_id', $payment->banjar_id)->where('user_id', $actor->id)->value('phone');
        $phone = is_string($phone) ? preg_replace('/[^0-9]/', '', $phone) : '';
        $email = filter_var($actor->email, FILTER_VALIDATE_EMAIL) ? $actor->email : (string) config('payment.ipaymu.fallback_email');
        $items = $bills->map(fn ($bill) => ['name' => mb_substr(($bill->title ?: 'Tagihan').' · '.$banjar.' (tagihan #'.$bill->bill_id.')', 0, 200), 'price' => (int) $bill->amount])->all();
        if ((int) $payment->service_fee > 0) {
            $items[] = ['name' => 'Biaya layanan aplikasi', 'price' => (int) $payment->service_fee];
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
        $billIds = DB::table('payment_items')->where('payment_id', $payment->id)->pluck('bill_id');
        abort_unless($billIds->isNotEmpty() && $this->access->bills($actor)->whereIn('id', $billIds)->count() === $billIds->count(), 404);

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
                $items = DB::table('payment_items')->where('payment_id', $payment->id)->orderBy('bill_id')->get();
                $bills = DB::table('bills')->where('banjar_id', $payment->banjar_id)->whereIn('id', $items->pluck('bill_id'))->get()->keyBy('id');
                $settled = DB::table('bill_settlements')->where('banjar_id', $payment->banjar_id)->whereIn('bill_id', $items->pluck('bill_id'))->whereNull('reversed_at')->pluck('bill_id')->flip();
                // Tagihan yang sudah lunas lewat jalur lain (tunai/QRIS lain) tidak dilunasi lagi; uangnya ditahan di EXCEPTION.
                $payable = $payment->blocked ? collect() : $items->filter(fn ($item) => ($bills->get($item->bill_id)->status ?? null) === 'UNPAID' && ! $settled->has($item->bill_id));
                $conflict = $items->isEmpty() || $payable->count() !== $items->count();
                $billAmount = (int) $payment->bill_amount;
                $serviceFee = (int) $payment->service_fee;
                $toBanjar = (int) $payable->sum('amount');
                // Nilai tagihan milik Banjar; biaya layanan milik platform. Pembayaran ganda/konflik ditahan di EXCEPTION.
                $this->ledger->post($payment, 'received', ['CLEARING' => (int) $payment->gross_amount], array_filter(['PENDING' => $toBanjar, 'EXCEPTION' => $billAmount - $toBanjar, 'PLATFORM' => $serviceFee]));
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
                foreach ($payable as $item) {
                    DB::table('bill_settlements')->insert(['banjar_id' => $payment->banjar_id, 'bill_id' => $item->bill_id, 'method' => 'GATEWAY', 'amount' => $item->amount,
                        'payment_id' => $payment->id, 'created_by' => $payment->created_by, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('bills')->whereIn('id', $payable->pluck('bill_id'))->update(['status' => 'PAID', 'updated_at' => now()]);
                if ($conflict) {
                    // Tagihan lain dalam QRIS ini tetap lunas; dana pembayaran ini ditahan sampai diperiksa Bali Santih.
                    $this->block($payment, 'SETTLEMENT_CONFLICT');
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

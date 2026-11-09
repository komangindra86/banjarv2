<?php

namespace App\Modules\Payment;

use App\Platform\Audit\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nilai tagihan QRIS ditahan (payment.settlement_hold_days) sejak lunas, lalu otomatis dapat dicairkan Banjar
 * (PENDING → AVAILABLE) setelah status di iPaymu diperiksa ulang dan masih lunas (tidak dibatalkan/di-refund).
 * Dijalankan scheduler lewat payments:reconcile.
 */
final class SettlementManager
{
    public function __construct(private LedgerService $ledger, private Audit $audit) {}

    public function release(int $id): void
    {
        $payment = DB::table('payments')->find($id);
        if (! $payment || $payment->status !== 'PAID' || $payment->blocked || $payment->released_at || ! $payment->available_at || Carbon::parse($payment->available_at)->isFuture()) {
            // Belum waktunya: cukup perbarui status dari penyedia bila masih menunggu pembayaran.
            if ($payment && ! $payment->paid_at && in_array($payment->status, ['CREATING', 'UNKNOWN', 'PENDING'])) {
                app(PaymentManager::class)->sync($id);
            } elseif ($payment) {
                // Lunas & masih ditahan: periksa lagi saat masa tahan habis; selebihnya cukup sehari sekali.
                $waiting = $payment->status === 'PAID' && ! $payment->released_at && $payment->available_at && Carbon::parse($payment->available_at)->isFuture();
                DB::table('payments')->where('id', $id)->update(['next_check_at' => $waiting ? $payment->available_at : now()->addDay()]);
            }

            return;
        }
        // Pemeriksaan status terbaru gagal/berubah: dana tetap tertahan, dicoba lagi nanti.
        if (! app(PaymentManager::class)->sync($id)) {
            return;
        }
        DB::transaction(function () use ($payment) {
            DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->find($payment->id);
            if ($payment->status !== 'PAID' || $payment->blocked || $payment->released_at || ! $payment->available_at || Carbon::parse($payment->available_at)->isFuture()) {
                return;
            }
            abort_unless(PaymentManager::settlementsIntact($payment), 409);
            $this->ledger->post($payment, 'available', ['PENDING' => (int) $payment->net_amount], ['AVAILABLE' => (int) $payment->net_amount]);
            DB::table('payments')->where('id', $payment->id)->update(['released_at' => now(), 'updated_at' => now()]);
            $this->audit->record($payment->banjar_id, null, 'payment.available', 'payments', $payment->id, [], ['amount' => $payment->net_amount]);
        }, 3);
    }
}

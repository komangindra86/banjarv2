<?php

namespace App\Modules\Payment;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SettlementManager
{
    public function __construct(private Access $access, private LedgerService $ledger, private Audit $audit) {}

    public function submit(User $actor, int $id, array $input): void
    {
        $this->access->requirePlatform($actor);
        $data = Validator::make($input, ['reference' => ['required', 'string', 'min:10', 'max:1000'], 'gateway_fee' => ['required', 'regex:/^(0|[1-9][0-9]{0,11})$/'],
            'settled_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.now('Asia/Makassar')->format('Y-m-d\TH:i')]])->validate();
        $payment = DB::table('payments')->find($id);
        abort_unless($payment, 404);
        DB::transaction(function () use ($actor, $payment, $data) {
            DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
            $this->access->requirePlatform($actor);
            $payment = DB::table('payments')->find($payment->id);
            abort_unless($payment->status === 'PAID' && ! $payment->blocked && $payment->hold_days !== null && ! $payment->released_at, 409, 'Pembayaran belum memenuhi policy settlement.');
            abort_unless((int) $data['gateway_fee'] < $payment->gross_amount, 422);
            $settled = Carbon::createFromFormat('Y-m-d\TH:i', $data['settled_at'], 'Asia/Makassar')->startOfMinute()->utc();
            abort_unless($settled->gte(Carbon::parse($payment->created_at)->startOfMinute()), 422);
            abort_if(DB::table('payment_settlement_evidence')->where('payment_id', $payment->id)->exists(), 409);
            DB::table('payment_settlement_evidence')->insert(['banjar_id' => $payment->banjar_id, 'payment_id' => $payment->id, 'reference' => Crypt::encryptString($data['reference']),
                'gateway_fee' => $data['gateway_fee'], 'settled_at' => $settled, 'submitted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record($payment->banjar_id, $actor->id, 'payment.evidence_submitted', 'payments', $payment->id);
        }, 3);
    }

    public function approve(User $actor, int $id): void
    {
        $this->access->requirePlatform($actor);
        $payment = DB::table('payments')->find($id);
        abort_unless($payment, 404);
        DB::transaction(function () use ($actor, $payment) {
            DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
            $this->access->requirePlatform($actor);
            $payment = DB::table('payments')->find($payment->id);
            $evidence = DB::table('payment_settlement_evidence')->where('payment_id', $payment->id)->first();
            abort_unless($evidence && $evidence->submitted_by !== $actor->id, 403, 'Pemeriksa bukti harus Super Admin yang berbeda.');
            if ($evidence->approved_at) {
                return;
            }
            abort_unless($payment->status === 'PAID' && ! $payment->blocked && $payment->hold_days !== null, 409);
            $fee = (int) $evidence->gateway_fee;
            if ($fee > 0) {
                $this->ledger->post($payment, 'fee', ['PENDING' => $fee], ['FEE' => $fee], $actor->id);
            }
            DB::table('payment_settlement_evidence')->where('id', $evidence->id)->update(['approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            DB::table('payments')->where('id', $payment->id)->update(['gateway_fee' => $fee, 'net_amount' => $payment->gross_amount - $fee,
                'settlement_at' => $evidence->settled_at, 'available_at' => Carbon::parse($evidence->settled_at)->addDays($payment->hold_days), 'updated_at' => now()]);
            $this->audit->record($payment->banjar_id, $actor->id, 'payment.evidence_approved', 'payments', $payment->id);
        }, 3);
    }

    public function release(int $id): void
    {
        // A failed provider check leaves funds pending; caller must retry later.
        if (! app(PaymentManager::class)->sync($id)) {
            return;
        }
        $payment = DB::table('payments')->find($id);
        DB::transaction(function () use ($payment) {
            DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->find($payment->id);
            if ($payment->status !== 'PAID' || $payment->blocked || $payment->released_at || ! $payment->available_at || Carbon::parse($payment->available_at)->isFuture()) {
                return;
            }
            abort_unless(DB::table('payment_settlement_evidence')->where('payment_id', $payment->id)->whereNotNull('approved_at')->exists(), 409);
            abort_unless(DB::table('bill_settlements')->where('payment_id', $payment->id)->whereNull('reversed_at')->where('amount', $payment->gross_amount)->exists()
                && DB::table('bills')->where('id', $payment->bill_id)->value('status') === 'PAID', 409);
            $this->ledger->post($payment, 'available', ['PENDING' => (int) $payment->net_amount], ['AVAILABLE' => (int) $payment->net_amount]);
            DB::table('payments')->where('id', $payment->id)->update(['released_at' => now(), 'updated_at' => now()]);
            $this->audit->record($payment->banjar_id, null, 'payment.available', 'payments', $payment->id, [], ['amount' => $payment->net_amount]);
        }, 3);
    }
}

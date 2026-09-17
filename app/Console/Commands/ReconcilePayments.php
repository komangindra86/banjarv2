<?php

namespace App\Console\Commands;

use App\Modules\Payment\PaymentManager;
use App\Modules\Payment\SettlementManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=100 : Maximum payments and receipts per run}';

    protected $description = 'Retry durable receipts, reconcile provider status and release evidenced gateway funds';

    public function handle(PaymentManager $manager, SettlementManager $settlements): int
    {
        $lock = Cache::lock('payments:reconcile', 3600);
        if (! $lock->get()) {
            $this->info('Reconciliation already running.');

            return self::SUCCESS;
        }
        $run = DB::table('reconciliation_runs')->insertGetId(['started_at' => now()]);
        $checked = $failed = 0;
        try {
            $limit = max(1, min(100, (int) $this->option('limit')));
            DB::table('payment_webhook_receipts')->where('status', '<>', 'PROCESSED')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')->limit($limit)->pluck('id')->each(fn ($id) => $manager->processReceipt($id));
            $payments = DB::table('payments')->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
                ->orderBy('next_check_at')->orderBy('id')->limit($limit)->get();
            foreach ($payments as $payment) {
                try {
                    $this->checkIntegrity($payment->id);
                    $settlements->release($payment->id);
                    $this->checkIntegrity($payment->id);
                    $checked++;
                } catch (Throwable) {
                    $failed++;
                    DB::table('payments')->where('id', $payment->id)->update(['next_check_at' => now()->addMinutes(15)]);
                }
            }
            DB::table('reconciliation_runs')->where('id', $run)->update(['finished_at' => now(), 'checked' => $checked, 'failed' => $failed]);
        } finally {
            $lock->release();
        }
        $this->info("Checked: {$checked}; retry required: {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkIntegrity(int $id): void
    {
        $payment = DB::table('payments')->find($id);
        DB::transaction(function () use ($payment) {
            DB::table('banjars')->where('id', $payment->banjar_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->find($payment->id);
            if (! $payment->paid_at) {
                return;
            }
            $journals = DB::table('financial_ledger_transactions')->where('payment_id', $payment->id)->get();
            $valid = $journals->where('type', 'received')->count() === 1;
            foreach ($journals as $journal) {
                $entries = DB::table('financial_ledger_entries')->where('transaction_id', $journal->id)->get();
                $valid = $valid && $entries->where('direction', 'DEBIT')->sum('amount') === $entries->where('direction', 'CREDIT')->sum('amount');
            }
            if ($payment->status === 'PAID') {
                $settlement = DB::table('bill_settlements')->where('payment_id', $payment->id)->whereNull('reversed_at')->first();
                $valid = $valid && $settlement && (int) $settlement->amount === (int) $payment->gross_amount && DB::table('bills')->where('id', $payment->bill_id)->value('status') === 'PAID';
            }
            if (! $valid) {
                app(PaymentManager::class)->flagIntegrity($payment->id);
            }
        }, 3);
    }
}

<?php

namespace App\Modules\Payment;

use Illuminate\Support\Facades\DB;

final class LedgerService
{
    // Internal accounting boundary; callers supply verified payments, never browser data.
    public function post(object $payment, string $type, array $debits, array $credits, ?int $actor = null): int
    {
        return $this->write((int) $payment->banjar_id, 'payment:'.$payment->id.':'.$type, ['payment_id' => $payment->id], $type, $debits, $credits, $actor);
    }

    // Withdrawal movements: reserve (AVAILABLE→RESERVED), paid (RESERVED→PAID_OUT), release (RESERVED→AVAILABLE).
    public function postWithdrawal(object $withdrawal, string $type, array $debits, array $credits, ?int $actor = null): int
    {
        abort_unless(in_array($type, ['reserve', 'paid', 'release'], true), 409);

        return $this->write((int) $withdrawal->banjar_id, 'withdrawal:'.$withdrawal->id.':'.$type, ['withdrawal_id' => $withdrawal->id], $type, $debits, $credits, $actor);
    }

    private function write(int $tenant, string $key, array $source, string $type, array $debits, array $credits, ?int $actor): int
    {
        return DB::transaction(function () use ($tenant, $key, $source, $type, $debits, $credits, $actor) {
            DB::table('banjars')->where('id', $tenant)->lockForUpdate()->firstOrFail();
            abort_unless(array_sum($debits) === array_sum($credits) && array_sum($debits) > 0, 409);
            ksort($debits);
            ksort($credits);
            $hash = hash('sha256', json_encode([$debits, $credits]));
            $existing = DB::table('financial_ledger_transactions')->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409);

                return $existing->id;
            }
            $base = ['banjar_id' => $tenant, 'created_at' => now(), 'updated_at' => now()];
            $id = DB::table('financial_ledger_transactions')->insertGetId($base + $source + ['type' => $type, 'idempotency_key' => $key, 'payload_hash' => $hash, 'actor_id' => $actor]);
            foreach (['DEBIT' => $debits, 'CREDIT' => $credits] as $direction => $entries) {
                foreach ($entries as $bucket => $amount) {
                    abort_unless(is_int($amount) && $amount > 0 && $amount <= 999999999999, 409);
                    if ($direction === 'DEBIT' && $bucket !== 'CLEARING') {
                        abort_unless($this->balance($tenant, $bucket) >= $amount, 409, 'Saldo ledger tidak mencukupi.');
                    }
                    DB::table('financial_accounts')->insertOrIgnore($base + ['bucket' => $bucket, 'currency' => 'IDR']);
                    $account = DB::table('financial_accounts')->where('banjar_id', $tenant)->where('bucket', $bucket)->value('id');
                    DB::table('financial_ledger_entries')->insert($base + ['transaction_id' => $id, 'account_id' => $account, 'direction' => $direction, 'amount' => $amount]);
                }
            }

            return $id;
        }, 3);
    }

    public function balance(int $tenant, string $bucket): int
    {
        return (int) DB::table('financial_ledger_entries as e')->join('financial_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('e.banjar_id', $tenant)->where('a.banjar_id', $tenant)->where('a.bucket', $bucket)
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'CREDIT' THEN CAST(e.amount AS SIGNED) ELSE -CAST(e.amount AS SIGNED) END), 0) AS balance")->value('balance');
    }
}

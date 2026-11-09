<?php

namespace App\Modules\Payment;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Notification\NotificationManager;
use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use App\Platform\Tenancy\CurrentBanjar;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Manual withdrawal V1. Money only moves through ledger journals:
 * reserve AVAILABLE→RESERVED, paid RESERVED→PAID_OUT, release RESERVED→AVAILABLE.
 * Lock order: banjars guard row → withdrawal row. The bank transfer itself happens outside the system.
 */
final class WithdrawalManager
{
    public const STATUSES = ['APPROVAL_PENDING' => 'Menunggu persetujuan Kelian', 'REQUESTED' => 'Menunggu diproses Bali Santih', 'PROCESSING' => 'Sedang ditransfer', 'PAID' => 'Sudah ditransfer', 'FAILED' => 'Gagal, dana dikembalikan', 'CANCELLED' => 'Dibatalkan, dana dikembalikan'];

    public const OPEN = ['APPROVAL_PENDING', 'REQUESTED', 'PROCESSING'];

    public function __construct(private PaymentAccess $payments, private Access $access, private CurrentBanjar $tenant, private LedgerService $ledger, private BankAccountManager $accounts, private WithdrawalProof $proofs, private Audit $audit, private NotificationManager $notifications) {}

    public function visible(User $actor, int $id): object
    {
        $this->payments->authorize($actor, 'withdrawal.view');
        $withdrawal = DB::table('withdrawals')->where('banjar_id', $this->tenant->id())->where('id', $id)->first();
        abort_unless($withdrawal, 404);

        return $withdrawal;
    }

    public function request(User $actor, array $input): int
    {
        $this->payments->authorize($actor, 'withdrawal.request');
        // Destination is never taken from the form; only amount and an idempotency key are accepted.
        $data = Validator::make($input, ['amount' => ['required', 'regex:/^[1-9][0-9]{0,11}$/'], 'submission_key' => ['required', 'uuid']], [], ['amount' => 'nominal'])->validate();
        $amount = (int) $data['amount'];

        return DB::transaction(function () use ($actor, $data, $amount) {
            $tenant = $this->tenant->id();
            DB::table('banjars')->where('id', $tenant)->lockForUpdate()->firstOrFail();
            $this->payments->authorize($actor, 'withdrawal.request');
            $existing = DB::table('withdrawals')->where('banjar_id', $tenant)->where('request_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->amount === $amount && (int) $existing->requested_by === $actor->id, 409, 'Permintaan ini sudah dikirim dengan isi berbeda.');

                return $existing->id;
            }
            $account = $this->accounts->primary($tenant);
            if (! $account) {
                throw ValidationException::withMessages(['amount' => 'Belum ada rekening Banjar yang terverifikasi Bali Santih.']);
            }
            $available = $this->ledger->balance($tenant, 'AVAILABLE');
            if ($amount > $available) {
                throw ValidationException::withMessages(['amount' => 'Nominal melebihi saldo tersedia ('.FinanceManager::money(max(0, $available)).').']);
            }
            $approval = (bool) DB::table('banjar_settings')->where('banjar_id', $tenant)->value('payout_requires_kelian_approval');
            $status = $approval ? 'APPROVAL_PENDING' : 'REQUESTED';
            $id = DB::table('withdrawals')->insertGetId(['banjar_id' => $tenant, 'reference' => 'WD-'.Str::ulid(), 'request_key' => $data['submission_key'],
                'bank_account_id' => $account->id, 'bank_code' => $account->bank_code, 'bank_name' => $account->bank_name,
                'account_number_encrypted' => $account->account_number_encrypted, 'account_number_masked' => $account->account_number_masked,
                'account_holder_name' => $account->account_holder_name, 'amount' => $amount, 'requires_kelian_approval' => $approval,
                'status' => $status, 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $withdrawal = DB::table('withdrawals')->find($id);
            $this->ledger->postWithdrawal($withdrawal, 'reserve', ['AVAILABLE' => $amount], ['RESERVED' => $amount], $actor->id);
            $this->step($withdrawal, 'requested', null, $status, $actor, 'TENANT');
            $this->audit->record($tenant, $actor->id, 'withdrawal.requested', 'withdrawals', $id, [], ['amount' => $amount, 'status' => $status, 'bank_account_id' => $account->id, 'account_number_masked' => $account->account_number_masked]);
            if ($status === 'APPROVAL_PENDING') {
                $this->notifications->notifyPermission($tenant, 'withdrawal.approve', 'withdrawal.approval_pending', 'Pencairan menunggu persetujuan', 'Ada permintaan pencairan menunggu persetujuan Kelian.', '/admin/withdrawals');
            }

            return $id;
        }, 3);
    }

    // Applies to new requests only; each withdrawal snapshots the policy it was created under.
    public function policy(User $actor, array $input): void
    {
        $this->payments->authorize($actor, 'withdrawal.configure');
        $data = Validator::make($input, ['payout_requires_kelian_approval' => ['required', 'boolean']])->validate();
        $tenant = $this->tenant->id();
        DB::transaction(function () use ($actor, $data, $tenant) {
            DB::table('banjars')->where('id', $tenant)->lockForUpdate()->firstOrFail();
            $this->payments->authorize($actor, 'withdrawal.configure');
            $old = (bool) DB::table('banjar_settings')->where('banjar_id', $tenant)->value('payout_requires_kelian_approval');
            $new = (bool) $data['payout_requires_kelian_approval'];
            DB::table('banjar_settings')->where('banjar_id', $tenant)->update(['payout_requires_kelian_approval' => $new, 'updated_at' => now()]);
            $this->audit->record($tenant, $actor->id, 'withdrawal.policy_updated', 'banjars', $tenant, ['payout_requires_kelian_approval' => $old], ['payout_requires_kelian_approval' => $new]);
        }, 3);
    }

    public function approve(User $actor, int $id, array $input): void
    {
        $this->payments->authorize($actor, 'withdrawal.approve');
        $data = Validator::make($input, ['decision' => ['required', Rule::in(['APPROVE', 'REJECT'])], 'note' => ['nullable', 'required_if:decision,REJECT', 'string', 'min:5', 'max:500']])->validate();
        $this->tenantWrite($actor, 'withdrawal.approve', $id, function (object $w) use ($actor, $data) {
            $to = $data['decision'] === 'APPROVE' ? 'REQUESTED' : 'CANCELLED';
            if ($w->status === $to && (int) $w->approved_by === $actor->id) {
                return;
            }
            abort_unless($w->status === 'APPROVAL_PENDING', 409, 'Permintaan sudah tidak menunggu persetujuan.');
            // Separation of duties: a requester holding Kelian rights cannot approve their own request.
            abort_if((int) $w->requested_by === $actor->id, 403, 'Kelian tidak dapat menyetujui permintaan yang diajukannya sendiri.');
            DB::table('withdrawals')->where('id', $w->id)->update(['status' => $to, 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]
                + ($to === 'CANCELLED' ? ['closing_note' => $data['note']] : []));
            if ($to === 'CANCELLED') {
                $this->ledger->postWithdrawal($w, 'release', ['RESERVED' => (int) $w->amount], ['AVAILABLE' => (int) $w->amount], $actor->id);
            }
            $this->step($w, $to === 'REQUESTED' ? 'approved' : 'rejected', $w->status, $to, $actor, 'TENANT', $data['note'] ?? null);
            $this->audit->record($w->banjar_id, $actor->id, 'withdrawal.'.($to === 'REQUESTED' ? 'approved' : 'rejected'), 'withdrawals', $w->id, ['status' => $w->status], ['status' => $to]);
            if ($to === 'CANCELLED') {
                $this->notifications->notify((int) $w->banjar_id, (int) $w->requested_by, 'withdrawal.resolved', 'Pencairan ditolak', 'Permintaan pencairan Anda ditolak oleh Kelian.', '/admin/withdrawals');
            }
        });
    }

    public function cancel(User $actor, int $id, array $input): void
    {
        $this->payments->authorize($actor, 'withdrawal.request');
        $data = Validator::make($input, ['note' => ['required', 'string', 'min:5', 'max:500']], [], ['note' => 'alasan'])->validate();
        $this->tenantWrite($actor, 'withdrawal.request', $id, function (object $w) use ($actor, $data) {
            if ($w->status === 'CANCELLED') {
                return;
            }
            // Once Bali Santih claims the transfer, the tenant can no longer pull the funds back.
            abort_unless(in_array($w->status, ['APPROVAL_PENDING', 'REQUESTED'], true), 409, 'Pencairan sedang diproses dan tidak dapat dibatalkan dari Banjar.');
            $this->close($w, 'CANCELLED', $actor, 'TENANT', 'cancelled', $data['note']);
        });
    }

    public function claim(User $actor, int $id): void
    {
        $this->platformWrite($actor, $id, function (object $w) use ($actor) {
            if ($w->status === 'PROCESSING' && (int) $w->claimed_by === $actor->id) {
                return;
            }
            abort_unless($w->status === 'REQUESTED', 409, $w->status === 'PROCESSING' ? 'Pencairan sudah diambil operator lain.' : 'Pencairan belum siap diproses.');
            abort_unless(DB::table('banjars')->where('id', $w->banjar_id)->value('status') === 'ACTIVE', 409, 'Banjar tidak aktif. Tinjau status sebelum mentransfer.');
            $account = DB::table('banjar_bank_accounts')->where('banjar_id', $w->banjar_id)->where('id', $w->bank_account_id)->first();
            abort_unless($account && in_array($account->verification_status, ['VERIFIED', 'REPLACED'], true), 409, 'Rekening tujuan diblokir. Batalkan atau tinjau pencairan ini.');
            DB::table('withdrawals')->where('id', $w->id)->update(['status' => 'PROCESSING', 'claimed_by' => $actor->id, 'claimed_at' => now(), 'updated_at' => now()]);
            $this->step($w, 'claimed', 'REQUESTED', 'PROCESSING', $actor, 'PLATFORM');
            $this->audit->record($w->banjar_id, $actor->id, 'withdrawal.claimed', 'withdrawals', $w->id, ['status' => 'REQUESTED'], ['status' => 'PROCESSING']);
        });
    }

    // Full destination number only for the operator holding the claim; each reveal is audited.
    public function revealDestination(User $actor, int $id): string
    {
        $this->requireOperator($actor);
        $w = DB::table('withdrawals')->find($id);
        abort_unless($w, 404);
        abort_unless($w->status === 'PROCESSING' && (int) $w->claimed_by === $actor->id, 403, 'Ambil pencairan terlebih dahulu untuk melihat nomor rekening lengkap.');
        $this->audit->record($w->banjar_id, $actor->id, 'withdrawal.destination_revealed', 'withdrawals', $w->id);

        return Crypt::decryptString($w->account_number_encrypted);
    }

    public function markPaid(User $actor, int $id, array $input): void
    {
        $this->requireOperator($actor);
        $input['bank_reference'] = trim((string) ($input['bank_reference'] ?? ''));
        $data = Validator::make($input, [
            'bank_reference' => ['required', 'string', 'min:4', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/_-]*$/'],
            'transferred_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.now('Asia/Makassar')->format('Y-m-d\TH:i')],
            'proof' => WithdrawalProof::RULES,
        ], [], ['bank_reference' => 'nomor referensi transfer', 'transferred_at' => 'waktu transfer', 'proof' => 'bukti transfer'])->validate();
        $existing = DB::table('withdrawals')->find($id);
        abort_unless($existing, 404);
        $hash = hash_hmac('sha256', strtoupper(preg_replace('/\s+/', '', $data['bank_reference'])), (string) config('app.key'));
        $transferred = Carbon::createFromFormat('Y-m-d\TH:i', $data['transferred_at'], 'Asia/Makassar')->startOfMinute()->utc();
        /** @var UploadedFile $file */
        $file = $data['proof'];
        // File is written before the commit; any rejection below removes it so no orphan remains.
        $stored = $this->proofs->store((int) $existing->banjar_id, $file);
        $keep = false;
        try {
            $this->platformWrite($actor, $id, function (object $w) use ($actor, $hash, $transferred, $data, $stored, &$keep) {
                if ($w->status === 'PAID') {
                    // Double submit of the same transfer is harmless; a different reference is a conflict.
                    abort_unless(hash_equals((string) $w->bank_reference_hash, $hash), 409, 'Pencairan sudah ditandai dibayar dengan referensi lain.');

                    return;
                }
                abort_unless($w->status === 'PROCESSING', 409, 'Hanya pencairan yang sedang diproses dapat ditandai dibayar.');
                abort_unless((int) $w->claimed_by === $actor->id, 403, 'Pencairan diproses oleh operator lain.');
                if (DB::table('withdrawals')->where('bank_reference_hash', $hash)->where('id', '<>', $w->id)->exists()) {
                    throw ValidationException::withMessages(['bank_reference' => 'Nomor referensi transfer sudah dipakai pada pencairan lain.']);
                }
                if ($transferred->lt(Carbon::parse($w->claimed_at)->startOfMinute())) {
                    throw ValidationException::withMessages(['transferred_at' => 'Waktu transfer tidak boleh sebelum pencairan diambil untuk diproses.']);
                }
                DB::table('withdrawals')->where('id', $w->id)->update($stored + ['status' => 'PAID', 'processed_by' => $actor->id, 'processed_at' => now(),
                    'bank_reference_encrypted' => Crypt::encryptString($data['bank_reference']), 'bank_reference_hash' => $hash, 'transferred_at' => $transferred, 'updated_at' => now()]);
                $this->ledger->postWithdrawal($w, 'paid', ['RESERVED' => (int) $w->amount], ['PAID_OUT' => (int) $w->amount], $actor->id);
                // Uang sudah di rekening Banjar: catat otomatis di buku kas sebagai pemindahan (bukan pendapatan).
                FinanceManager::recordQrisPayout(DB::table('withdrawals')->where('id', $w->id)->first(), $actor->id);
                $this->step($w, 'paid', 'PROCESSING', 'PAID', $actor, 'PLATFORM');
                $this->audit->record($w->banjar_id, $actor->id, 'withdrawal.paid', 'withdrawals', $w->id, ['status' => 'PROCESSING'], ['status' => 'PAID', 'amount' => $w->amount]);
                $this->notifications->notify((int) $w->banjar_id, (int) $w->requested_by, 'withdrawal.resolved', 'Pencairan selesai', 'Permintaan pencairan Anda telah ditransfer.', '/admin/withdrawals');
                $keep = true;
            });
        } catch (Throwable $e) {
            $keep = false;
            throw $e;
        } finally {
            if (! $keep) {
                $this->proofs->discard($stored['proof_path']);
            }
        }
    }

    public function markFailed(User $actor, int $id, array $input): void
    {
        $this->requireOperator($actor);
        $data = Validator::make($input, ['note' => ['required', 'string', 'min:10', 'max:500'], 'confirm_not_sent' => ['accepted']],
            ['confirm_not_sent.accepted' => 'Tandai gagal hanya jika sudah dipastikan dana tidak terkirim.'], ['note' => 'alasan'])->validate();
        $this->platformWrite($actor, $id, function (object $w) use ($actor, $data) {
            if ($w->status === 'FAILED') {
                return;
            }
            abort_unless($w->status === 'PROCESSING', 409, 'Hanya pencairan yang sedang diproses dapat ditandai gagal.');
            abort_unless((int) $w->claimed_by === $actor->id, 403, 'Pencairan diproses oleh operator lain.');
            $this->close($w, 'FAILED', $actor, 'PLATFORM', 'failed', $data['note']);
        });
    }

    // Platform may decline a request that has not been claimed (e.g. blocked account or inactive Banjar).
    public function platformCancel(User $actor, int $id, array $input): void
    {
        $this->requireOperator($actor);
        $data = Validator::make($input, ['note' => ['required', 'string', 'min:10', 'max:500']], [], ['note' => 'alasan'])->validate();
        $this->platformWrite($actor, $id, function (object $w) use ($actor, $data) {
            if ($w->status === 'CANCELLED') {
                return;
            }
            abort_unless(in_array($w->status, ['APPROVAL_PENDING', 'REQUESTED'], true), 409, 'Pencairan yang sudah diproses tidak dapat dibatalkan. Gunakan tandai gagal bila dana dipastikan tidak terkirim.');
            $this->close($w, 'CANCELLED', $actor, 'PLATFORM', 'cancelled', $data['note']);
        });
    }

    public function proof(object $withdrawal): string
    {
        return $this->proofs->read($withdrawal);
    }

    /**
     * Ledger ↔ withdrawal invariants. Returns problem codes; empty means consistent.
     *
     * @return list<string>
     */
    public function invariants(int $tenant): array
    {
        $problems = [];
        $open = (int) DB::table('withdrawals')->where('banjar_id', $tenant)->whereIn('status', self::OPEN)->sum('amount');
        if ($this->ledger->balance($tenant, 'RESERVED') !== $open) {
            $problems[] = 'RESERVED_MISMATCH';
        }
        if ($this->ledger->balance($tenant, 'AVAILABLE') < 0) {
            $problems[] = 'AVAILABLE_NEGATIVE';
        }
        $paidOut = (int) DB::table('withdrawals')->where('banjar_id', $tenant)->where('status', 'PAID')->sum('amount');
        if ($this->ledger->balance($tenant, 'PAID_OUT') !== $paidOut) {
            $problems[] = 'PAID_OUT_MISMATCH';
        }
        $journals = DB::table('financial_ledger_transactions')->where('banjar_id', $tenant)->whereNotNull('withdrawal_id')
            ->selectRaw("withdrawal_id, SUM(type = 'reserve') AS reserves, SUM(type = 'paid') AS paids, SUM(type = 'release') AS releases")->groupBy('withdrawal_id')->get()->keyBy('withdrawal_id');
        foreach (DB::table('withdrawals')->where('banjar_id', $tenant)->get(['id', 'status']) as $w) {
            $j = $journals->get($w->id);
            [$reserve, $paid, $release] = [(int) ($j->reserves ?? 0), (int) ($j->paids ?? 0), (int) ($j->releases ?? 0)];
            $expected = match ($w->status) {
                'PAID' => [1, 1, 0],
                'FAILED', 'CANCELLED' => [1, 0, 1],
                default => [1, 0, 0],
            };
            if ([$reserve, $paid, $release] !== $expected) {
                $problems[] = 'JOURNAL_MISMATCH:'.$w->id;
            }
        }

        return $problems;
    }

    private function close(object $w, string $to, User $actor, string $scope, string $action, string $note): void
    {
        DB::table('withdrawals')->where('id', $w->id)->update(['status' => $to, 'closing_note' => $note, 'updated_at' => now()]
            + ($to === 'FAILED' ? ['processed_by' => $actor->id, 'processed_at' => now()] : []));
        $this->ledger->postWithdrawal($w, 'release', ['RESERVED' => (int) $w->amount], ['AVAILABLE' => (int) $w->amount], $actor->id);
        $this->step($w, $action, $w->status, $to, $actor, $scope, $note);
        $this->audit->record($w->banjar_id, $actor->id, 'withdrawal.'.$action, 'withdrawals', $w->id, ['status' => $w->status], ['status' => $to, 'amount' => $w->amount]);
        if ($scope === 'PLATFORM') {
            $this->notifications->notify((int) $w->banjar_id, (int) $w->requested_by, 'withdrawal.resolved', 'Pencairan diproses', 'Permintaan pencairan Anda telah '.($to === 'FAILED' ? 'ditandai gagal' : 'dibatalkan').'.', '/admin/withdrawals');
        }
    }

    private function step(object $w, string $action, ?string $from, string $to, User $actor, string $scope, ?string $note = null): void
    {
        DB::table('withdrawal_steps')->insert(['banjar_id' => $w->banjar_id, 'withdrawal_id' => $w->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $actor->id, 'actor_scope' => $scope, 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function tenantWrite(User $actor, string $permission, int $id, callable $work): void
    {
        $tenant = $this->tenant->id();
        DB::transaction(function () use ($actor, $permission, $id, $work, $tenant) {
            DB::table('banjars')->where('id', $tenant)->lockForUpdate()->firstOrFail();
            $this->payments->authorize($actor, $permission);
            $w = DB::table('withdrawals')->where('banjar_id', $tenant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($w, 404);
            $work($w);
        }, 3);
    }

    private function platformWrite(User $actor, int $id, callable $work): void
    {
        $existing = DB::table('withdrawals')->find($id);
        abort_unless($existing, 404);
        DB::transaction(function () use ($actor, $existing, $work) {
            DB::table('banjars')->where('id', $existing->banjar_id)->lockForUpdate()->firstOrFail();
            $this->requireOperator($actor);
            $w = DB::table('withdrawals')->where('banjar_id', $existing->banjar_id)->where('id', $existing->id)->lockForUpdate()->first();
            $work($w);
        }, 3);
    }

    private function requireOperator(User $actor): void
    {
        abort_unless($this->access->platform($actor) && $this->access->platform($actor, 'platform.withdrawal.process'), 403);
    }
}

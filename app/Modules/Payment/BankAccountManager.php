<?php

namespace App\Modules\Payment;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BankAccountManager
{
    // Curated list keeps input short; OTHER requires an explicit bank name.
    public const BANKS = ['129' => 'BPD Bali', '002' => 'BRI', '009' => 'BNI', '008' => 'Bank Mandiri', '014' => 'BCA', '451' => 'Bank Syariah Indonesia', '200' => 'BTN', '011' => 'Bank Danamon', '022' => 'CIMB Niaga', '013' => 'Bank Permata', 'OTHER' => 'Bank lainnya'];

    public const STATUSES = ['PENDING' => 'Menunggu verifikasi', 'VERIFIED' => 'Terverifikasi', 'REJECTED' => 'Ditolak', 'REPLACED' => 'Diganti rekening baru', 'DISABLED' => 'Diblokir'];

    public function __construct(private PaymentAccess $payments, private Access $access, private CurrentBanjar $tenant, private Audit $audit) {}

    public static function mask(string $number): string
    {
        return '**** '.substr($number, -4);
    }

    public function submit(User $actor, array $input): int
    {
        $this->payments->authorize($actor, 'bank.request');
        $input['account_number'] = preg_replace('/[\s.-]+/', '', (string) ($input['account_number'] ?? ''));
        $data = Validator::make($input, [
            'bank_code' => ['required', Rule::in(array_keys(self::BANKS))],
            'bank_name' => ['nullable', 'required_if:bank_code,OTHER', 'string', 'min:3', 'max:100'],
            'account_number' => ['required', 'regex:/^[0-9]{6,20}$/'],
            'account_holder_name' => ['required', 'string', 'min:3', 'max:150'],
        ], [], ['account_number' => 'nomor rekening', 'account_holder_name' => 'nama pemilik rekening'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->firstOrFail();
            $this->payments->authorize($actor, 'bank.request');
            if (DB::table('banjar_bank_accounts')->where('banjar_id', $this->tenant->id())->where('verification_status', 'PENDING')->exists()) {
                throw ValidationException::withMessages(['account_number' => 'Masih ada pengajuan rekening yang menunggu verifikasi Bali Santih.']);
            }
            $masked = self::mask($data['account_number']);
            $id = DB::table('banjar_bank_accounts')->insertGetId(['banjar_id' => $this->tenant->id(), 'bank_code' => $data['bank_code'],
                'bank_name' => $data['bank_code'] === 'OTHER' ? trim($data['bank_name']) : self::BANKS[$data['bank_code']],
                'account_number_encrypted' => Crypt::encryptString($data['account_number']), 'account_number_masked' => $masked,
                'account_holder_name' => trim($data['account_holder_name']), 'submitted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'bank_account.submitted', 'banjar_bank_accounts', $id, [], ['bank_code' => $data['bank_code'], 'account_number_masked' => $masked, 'status' => 'PENDING']);

            return $id;
        }, 3);
    }

    public function review(User $actor, int $id, array $input): void
    {
        $this->requireVerifier($actor);
        $data = Validator::make($input, ['decision' => ['required', Rule::in(['VERIFY', 'REJECT', 'DISABLE'])], 'note' => ['nullable', 'required_unless:decision,VERIFY', 'string', 'min:5', 'max:500']])->validate();
        $account = DB::table('banjar_bank_accounts')->find($id);
        abort_unless($account, 404);
        DB::transaction(function () use ($actor, $account, $data) {
            DB::table('banjars')->where('id', $account->banjar_id)->lockForUpdate()->firstOrFail();
            $this->requireVerifier($actor);
            $account = DB::table('banjar_bank_accounts')->where('id', $account->id)->lockForUpdate()->first();
            $from = $account->verification_status;
            $to = ['VERIFY' => 'VERIFIED', 'REJECT' => 'REJECTED', 'DISABLE' => 'DISABLED'][$data['decision']];
            if ($from === $to) {
                return;
            }
            $allowed = ['VERIFY' => ['PENDING'], 'REJECT' => ['PENDING'], 'DISABLE' => ['VERIFIED', 'REPLACED']][$data['decision']];
            abort_unless(in_array($from, $allowed, true), 409, 'Status rekening sudah berubah.');
            if ($to === 'VERIFIED') {
                // Clear the old primary first so the unique primary slot never holds two rows.
                DB::table('banjar_bank_accounts')->where('banjar_id', $account->banjar_id)->where('is_primary', true)
                    ->update(['is_primary' => false, 'verification_status' => 'REPLACED', 'updated_at' => now()]);
            }
            DB::table('banjar_bank_accounts')->where('id', $account->id)->update(['verification_status' => $to, 'is_primary' => $to === 'VERIFIED',
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null, 'updated_at' => now()]);
            $this->audit->record($account->banjar_id, $actor->id, 'bank_account.'.strtolower($to), 'banjar_bank_accounts', $account->id, ['status' => $from], ['status' => $to, 'account_number_masked' => $account->account_number_masked]);
        }, 3);
    }

    // Full number is shown only to a platform verifier for the transfer/verification task, and audited.
    public function reveal(User $actor, int $id): string
    {
        $this->requireVerifier($actor);
        $account = DB::table('banjar_bank_accounts')->find($id);
        abort_unless($account && in_array($account->verification_status, ['PENDING', 'VERIFIED'], true), 404);
        $this->audit->record($account->banjar_id, $actor->id, 'bank_account.number_revealed', 'banjar_bank_accounts', $account->id);

        return Crypt::decryptString($account->account_number_encrypted);
    }

    public function primary(int $tenant): ?object
    {
        return DB::table('banjar_bank_accounts')->where('banjar_id', $tenant)->where('is_primary', true)->where('verification_status', 'VERIFIED')->first();
    }

    private function requireVerifier(User $actor): void
    {
        abort_unless($this->access->platform($actor) && $this->access->platform($actor, 'bank.verify'), 403);
    }
}

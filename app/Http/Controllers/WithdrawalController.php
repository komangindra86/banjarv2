<?php

namespace App\Http\Controllers;

use App\Modules\Payment\BankAccountManager;
use App\Modules\Payment\LedgerService;
use App\Modules\Payment\PaymentAccess;
use App\Modules\Payment\WithdrawalManager;
use App\Platform\Identity\Access;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WithdrawalController extends Controller
{
    public function __construct(private WithdrawalManager $manager, private BankAccountManager $accounts, private PaymentAccess $payments, private CurrentBanjar $tenant, private Access $access) {}

    public function index(Request $request, LedgerService $ledger)
    {
        $user = $request->user();
        $this->payments->authorize($user, 'withdrawal.view');
        $tenant = $this->tenant->id();

        return view('withdrawal.index', [
            'available' => $ledger->balance($tenant, 'AVAILABLE'), 'reserved' => $ledger->balance($tenant, 'RESERVED'), 'paidOut' => $ledger->balance($tenant, 'PAID_OUT'),
            'account' => $this->accounts->primary($tenant),
            'pendingAccount' => DB::table('banjar_bank_accounts')->where('banjar_id', $tenant)->where('verification_status', 'PENDING')->first(),
            'withdrawals' => DB::table('withdrawals')->where('banjar_id', $tenant)->orderByDesc('id')->paginate(20),
            'requiresApproval' => (bool) DB::table('banjar_settings')->where('banjar_id', $tenant)->value('payout_requires_kelian_approval'),
            'canRequest' => $this->access->tenant($user, 'withdrawal.request'), 'canApprove' => $this->access->tenant($user, 'withdrawal.approve'),
            'canConfigure' => $this->access->tenant($user, 'withdrawal.configure'), 'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request)
    {
        $id = $this->manager->request($request->user(), $request->only(['amount', 'submission_key']));

        return redirect('/admin/withdrawals/'.$id)->with('success', 'Permintaan pencairan tercatat dan saldo telah dicadangkan.');
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        $withdrawal = $this->manager->visible($user, $id);

        return view('withdrawal.show', ['withdrawal' => $withdrawal, 'steps' => $this->steps($withdrawal),
            'canRequest' => $this->access->tenant($user, 'withdrawal.request'), 'canApprove' => $this->access->tenant($user, 'withdrawal.approve')]);
    }

    public function approve(Request $request, int $id)
    {
        $this->manager->approve($request->user(), $id, $request->only(['decision', 'note']));

        return back()->with('success', $request->input('decision') === 'APPROVE' ? 'Pencairan disetujui dan diteruskan ke Bali Santih.' : 'Pencairan ditolak. Saldo kembali tersedia.');
    }

    public function cancel(Request $request, int $id)
    {
        $this->manager->cancel($request->user(), $id, $request->only('note'));

        return back()->with('success', 'Pencairan dibatalkan. Saldo kembali tersedia.');
    }

    public function proof(Request $request, int $id)
    {
        return $this->proofResponse($this->manager->visible($request->user(), $id));
    }

    public function accounts(Request $request)
    {
        $user = $request->user();
        $this->payments->authorize($user, 'withdrawal.view');

        return view('withdrawal.accounts', ['accounts' => DB::table('banjar_bank_accounts')->where('banjar_id', $this->tenant->id())->orderByDesc('id')->get(),
            'canSubmit' => $this->access->tenant($user, 'bank.request')]);
    }

    public function storeAccount(Request $request)
    {
        $this->accounts->submit($request->user(), $request->only(['bank_code', 'bank_name', 'account_number', 'account_holder_name']));

        return back()->with('success', 'Rekening diajukan. Bali Santih akan memverifikasi sebelum dapat dipakai.');
    }

    public function policy(Request $request)
    {
        $this->manager->policy($request->user(), $request->only('payout_requires_kelian_approval'));

        return back()->with('success', 'Aturan persetujuan pencairan disimpan.');
    }

    public function platform()
    {
        $withdrawals = DB::table('withdrawals as w')->join('banjars as b', 'b.id', '=', 'w.banjar_id')->select('w.*', 'b.name as banjar_name')
            ->orderByRaw("FIELD(w.status, 'PROCESSING', 'REQUESTED', 'APPROVAL_PENDING') = 0")->orderByRaw("FIELD(w.status, 'PROCESSING', 'REQUESTED', 'APPROVAL_PENDING')")->orderBy('w.id')->paginate(25);
        $accounts = DB::table('banjar_bank_accounts as a')->join('banjars as b', 'b.id', '=', 'a.banjar_id')->where('a.verification_status', 'PENDING')
            ->select('a.*', 'b.name as banjar_name')->orderBy('a.id')->get();

        return view('withdrawal.platform', compact('withdrawals', 'accounts'));
    }

    public function platformShow(int $id, ?string $revealed = null)
    {
        $withdrawal = DB::table('withdrawals as w')->join('banjars as b', 'b.id', '=', 'w.banjar_id')->where('w.id', $id)->select('w.*', 'b.name as banjar_name', 'b.status as banjar_status')->first();
        abort_unless($withdrawal, 404);
        $account = DB::table('banjar_bank_accounts')->where('banjar_id', $withdrawal->banjar_id)->where('id', $withdrawal->bank_account_id)->first();

        return view('withdrawal.platform-show', ['withdrawal' => $withdrawal, 'account' => $account, 'steps' => $this->steps($withdrawal), 'revealed' => $revealed,
            'problems' => $this->manager->invariants((int) $withdrawal->banjar_id)]);
    }

    public function claim(Request $request, int $id)
    {
        $this->manager->claim($request->user('platform'), $id);

        return back()->with('success', 'Pencairan diambil. Lakukan transfer manual, lalu catat referensi dan bukti.');
    }

    public function reveal(Request $request, int $id)
    {
        return $this->platformShow($id, $this->manager->revealDestination($request->user('platform'), $id));
    }

    public function paid(Request $request, int $id)
    {
        $this->manager->markPaid($request->user('platform'), $id, $request->only(['bank_reference', 'transferred_at']) + ['proof' => $request->file('proof')]);

        return redirect('/platform/withdrawals/'.$id)->with('success', 'Pencairan ditandai sudah dibayar.');
    }

    public function failed(Request $request, int $id)
    {
        $this->manager->markFailed($request->user('platform'), $id, $request->only(['note', 'confirm_not_sent']));

        return back()->with('success', 'Pencairan ditandai gagal. Saldo Banjar kembali tersedia.');
    }

    public function platformCancel(Request $request, int $id)
    {
        $this->manager->platformCancel($request->user('platform'), $id, $request->only('note'));

        return back()->with('success', 'Pencairan dibatalkan. Saldo Banjar kembali tersedia.');
    }

    public function platformProof(Request $request, int $id)
    {
        abort_unless($this->access->platform($request->user('platform'), 'platform.withdrawal.process'), 403);
        $withdrawal = DB::table('withdrawals')->find($id);
        abort_unless($withdrawal, 404);

        return $this->proofResponse($withdrawal);
    }

    public function reviewAccount(Request $request, int $id)
    {
        $this->accounts->review($request->user('platform'), $id, $request->only(['decision', 'note']));

        return back()->with('success', 'Status rekening diperbarui.');
    }

    public function revealAccount(Request $request, int $id)
    {
        $number = $this->accounts->reveal($request->user('platform'), $id);
        $account = DB::table('banjar_bank_accounts as a')->join('banjars as b', 'b.id', '=', 'a.banjar_id')->where('a.id', $id)->select('a.*', 'b.name as banjar_name')->first();

        return view('withdrawal.account-reveal', compact('account', 'number'));
    }

    private function steps(object $withdrawal)
    {
        return DB::table('withdrawal_steps as s')->join('users as u', 'u.id', '=', 's.actor_id')->where('s.banjar_id', $withdrawal->banjar_id)
            ->where('s.withdrawal_id', $withdrawal->id)->select('s.*', 'u.name as actor_name')->orderBy('s.id')->get();
    }

    private function proofResponse(object $withdrawal)
    {
        $bytes = $this->manager->proof($withdrawal);
        $extension = $withdrawal->proof_mime === 'application/pdf' ? 'pdf' : 'webp';

        return response($bytes, 200, ['Content-Type' => $withdrawal->proof_mime, 'Content-Disposition' => 'attachment; filename="bukti-'.$withdrawal->reference.'.'.$extension.'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}

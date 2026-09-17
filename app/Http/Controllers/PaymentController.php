<?php

namespace App\Http\Controllers;

use App\Modules\Payment\LedgerService;
use App\Modules\Payment\PaymentAccess;
use App\Modules\Payment\PaymentManager;
use App\Modules\Payment\ProcessPaymentReceipt;
use App\Modules\Payment\SettlementManager;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class PaymentController extends Controller
{
    public function __construct(private PaymentAccess $access, private PaymentManager $manager, private CurrentBanjar $tenant) {}

    public function index(Request $request)
    {
        $bills = $this->access->bills($request->user())->orderByDesc('id')->paginate(20);
        $attemptsByBill = DB::table('payments')->where('banjar_id', $this->tenant->id())->whereIn('bill_id', $bills->pluck('id'))->orderByDesc('id')->get()->groupBy('bill_id');
        $events = DB::table('billing_events')->where('banjar_id', $this->tenant->id())->whereIn('id', $bills->pluck('billing_event_id'))->pluck('title', 'id');

        return view('payment.bills', compact('bills', 'attemptsByBill', 'events'));
    }

    public function create(Request $request, int $id)
    {
        return redirect('/payments/'.$this->manager->create($request->user(), $id));
    }

    public function show(Request $request, int $id)
    {
        $payment = $this->manager->visible($request->user(), $id);

        return view('payment.show', compact('payment'));
    }

    public function refresh(Request $request, int $id)
    {
        $this->manager->visible($request->user(), $id);
        try {
            $this->manager->refreshFor($request->user(), $id);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable) {
            return back()->withErrors(['payment' => 'Status belum dapat diperiksa. Pembayaran tetap tersimpan; coba lagi nanti.']);
        }

        return back()->with('success', 'Status pembayaran telah diperiksa.');
    }

    public function ledger(Request $request, LedgerService $ledger)
    {
        $this->access->authorize($request->user(), 'payment.view');
        $balances = [];
        foreach (['PENDING', 'AVAILABLE', 'RESERVED', 'PAID_OUT', 'FEE', 'EXCEPTION'] as $bucket) {
            $balances[$bucket] = $ledger->balance($this->tenant->id(), $bucket);
        }
        $payments = DB::table('payments')->where('banjar_id', $this->tenant->id())->orderByDesc('id')->paginate(20);
        $entries = DB::table('financial_ledger_entries as e')->join('financial_accounts as a', 'a.id', '=', 'e.account_id')
            ->join('financial_ledger_transactions as t', 't.id', '=', 'e.transaction_id')->where('e.banjar_id', $this->tenant->id())
            ->select('e.*', 'a.bucket', 't.type', 't.payment_id', 't.withdrawal_id')->orderByDesc('e.id')->limit(50)->get();

        return view('payment.ledger', compact('balances', 'payments', 'entries'));
    }

    public function webhook(Request $request)
    {
        abort_unless($request->attributes->get('platform'), 404);
        abort_if(strlen($request->getContent()) > 32768, 413);
        abort_unless($request->isJson(), 415);
        $id = $this->manager->receive($request->json()->all());
        try {
            ProcessPaymentReceipt::dispatch($id);
        } catch (Throwable) {
            // Durable inbox has committed. Scheduled reconciliation retries failed dispatches.
        }

        return response()->json(['received' => true]);
    }

    public function platform()
    {
        $payments = DB::table('payments as p')->join('banjars as b', 'b.id', '=', 'p.banjar_id')->select('p.*', 'b.name as banjar_name')->orderByDesc('p.id')->paginate(25);
        $items = DB::table('reconciliation_items')->where('status', 'OPEN')->orderByDesc('id')->limit(50)->get();
        $run = DB::table('reconciliation_runs')->orderByDesc('id')->first();

        return view('payment.platform', compact('payments', 'items', 'run'));
    }

    public function evidence(int $id)
    {
        $payment = DB::table('payments')->find($id);
        abort_unless($payment, 404);
        $evidence = DB::table('payment_settlement_evidence')->where('payment_id', $id)->first();

        return view('payment.evidence', compact('payment', 'evidence'));
    }

    public function submitEvidence(Request $request, int $id, SettlementManager $settlements)
    {
        $settlements->submit($request->user('platform'), $id, $request->all());

        return back()->with('success', 'Bukti tersimpan. Super Admin lain perlu memeriksanya.');
    }

    public function approveEvidence(Request $request, int $id, SettlementManager $settlements)
    {
        $settlements->approve($request->user('platform'), $id);

        return back()->with('success', 'Bukti disetujui. Dana tersedia setelah masa tunggu dan pemeriksaan status berikutnya.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Modules\Payment\LedgerService;
use App\Modules\Payment\PaymentAccess;
use App\Modules\Payment\PaymentManager;
use App\Modules\Payment\ProcessPaymentReceipt;
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
        // Yang belum lunas tampil di atas agar bisa dicentang dan dibayar sekaligus.
        $bills = $this->access->bills($request->user())->orderByRaw("status = 'UNPAID' DESC")->orderByDesc('id')->paginate(30);
        $attemptsByBill = DB::table('payment_items as i')->join('payments as p', 'p.id', '=', 'i.payment_id')->where('i.banjar_id', $this->tenant->id())
            ->whereIn('i.bill_id', $bills->pluck('id'))->orderByDesc('p.id')->get(['i.bill_id', 'p.id', 'p.status', 'p.blocked'])->groupBy('bill_id');
        $events = DB::table('billing_events')->where('banjar_id', $this->tenant->id())->whereIn('id', $bills->pluck('billing_event_id'))->pluck('title', 'id');
        $canPay = PaymentManager::ready() && ($this->access->can($request->user(), 'payment.create') || $this->access->can($request->user(), 'payment.self'));

        return view('payment.bills', compact('bills', 'attemptsByBill', 'events', 'canPay'));
    }

    // Satu QRIS untuk satu atau beberapa tagihan (bayar sekaligus).
    public function createMany(Request $request)
    {
        $data = $request->validate(['bill_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('payment.max_bills', 50)], 'bill_ids.*' => ['integer', 'min:1']],
            ['bill_ids.required' => 'Centang minimal satu tagihan yang akan dibayar.']);

        return redirect('/payments/'.$this->manager->create($request->user(), $data['bill_ids']));
    }

    public function create(Request $request, int $id)
    {
        return redirect('/payments/'.$this->manager->create($request->user(), $id));
    }

    public function show(Request $request, int $id)
    {
        $payment = $this->manager->visible($request->user(), $id);
        $items = PaymentManager::items($payment);

        return view('payment.show', compact('payment', 'items'));
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
        foreach (['PENDING', 'AVAILABLE', 'RESERVED', 'PAID_OUT', 'EXCEPTION'] as $bucket) {
            $balances[$bucket] = $ledger->balance($this->tenant->id(), $bucket);
        }
        $payments = DB::table('payments')->where('banjar_id', $this->tenant->id())->orderByDesc('id')->paginate(20);
        $itemCounts = DB::table('payment_items')->whereIn('payment_id', $payments->pluck('id'))->groupBy('payment_id')->selectRaw('payment_id, COUNT(*) AS n')->pluck('n', 'payment_id');
        $entries = DB::table('financial_ledger_entries as e')->join('financial_accounts as a', 'a.id', '=', 'e.account_id')
            ->join('financial_ledger_transactions as t', 't.id', '=', 'e.transaction_id')->where('e.banjar_id', $this->tenant->id())
            ->whereIn('a.bucket', ['PENDING', 'AVAILABLE', 'RESERVED', 'PAID_OUT', 'EXCEPTION'])
            ->select('e.*', 'a.bucket', 't.type', 't.payment_id', 't.withdrawal_id')->orderByDesc('e.id')->limit(50)->get();

        return view('payment.ledger', compact('balances', 'payments', 'entries', 'itemCounts'));
    }

    // notifyUrl iPaymu (dikirim ke alamat platform). Isinya hanya penunjuk; status dibaca ulang dari API iPaymu.
    public function webhook(Request $request)
    {
        abort_unless($request->attributes->get('platform'), 404);
        abort_if(strlen($request->getContent()) > 32768, 413);
        $id = $this->manager->receive($request->only(['trx_id', 'reference_id', 'status']));
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
        // Pendapatan layanan aplikasi (biaya layanan dikurangi biaya iPaymu) dan biaya iPaymu di seluruh Banjar.
        $totals = DB::table('financial_ledger_entries as e')->join('financial_accounts as a', 'a.id', '=', 'e.account_id')->whereIn('a.bucket', ['PLATFORM', 'FEE'])
            ->groupBy('a.bucket')->selectRaw("a.bucket, COALESCE(SUM(CASE WHEN e.direction = 'CREDIT' THEN CAST(e.amount AS SIGNED) ELSE -CAST(e.amount AS SIGNED) END), 0) AS total")->pluck('total', 'bucket');

        return view('payment.platform', compact('payments', 'items', 'run', 'totals'));
    }

    public function evidence(int $id)
    {
        $payment = DB::table('payments as p')->join('banjars as b', 'b.id', '=', 'p.banjar_id')->where('p.id', $id)->select('p.*', 'b.name as banjar_name')->first();
        abort_unless($payment, 404);
        $items = DB::table('reconciliation_items')->where('payment_id', $id)->orderByDesc('id')->get();
        $bills = PaymentManager::items($payment);

        return view('payment.evidence', compact('payment', 'items', 'bills'));
    }
}

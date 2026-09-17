<?php

namespace App\Http\Controllers;

use App\Modules\Finance\FinanceAccess;
use App\Modules\Finance\FinanceManager;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinanceController extends Controller
{
    public function __construct(private FinanceAccess $access, private FinanceManager $manager, private TenantQuery $query) {}

    public function index(Request $request)
    {
        $this->access->authorize($request->user());
        $accounts = $this->query->table('cash_accounts')->orderBy('name')->get()->map(function ($row) {
            $row->balance = $this->manager->balance($row->id);

            return $row;
        });
        $outstanding = $this->query->table('bills')->where('status', 'UNPAID')->sum('gross_amount');
        $events = $this->query->table('billing_events')->orderByDesc('id')->paginate(12);

        return view('finance.index', compact('accounts', 'outstanding', 'events'));
    }

    public function settings(Request $request)
    {
        $this->access->authorize($request->user());

        return view('finance.settings', ['types' => $this->query->table('billing_types')->orderBy('name')->paginate(20), 'policy' => $this->query->table('banjar_settings')->value('dedosan_requires_approval')]);
    }

    public function createEvent(Request $request)
    {
        $this->access->authorize($request->user(), 'billing.issue');
        $request->validate(['type' => ['required', 'integer']]);
        $type = $this->query->table('billing_types')->where('id', $request->integer('type'))->where('is_active', true)->first();
        abort_unless($type, 404);

        return view('finance.create-event', ['type' => $type, 'units' => $this->query->table('organizational_units')->where('is_active', true)->orderBy('name')->get()]);
    }

    public function event(Request $request, int $id)
    {
        $this->access->authorize($request->user());
        $event = $this->query->table('billing_events')->where('id', $id)->first();
        abort_unless($event, 404);
        $subjects = $this->query->table('billing_event_subjects')->where('billing_event_id', $id)->get();
        $people = $this->query->table('krama')->whereIn('id', $subjects->pluck('subject_krama_id'))->select('id', 'name')->get()->keyBy('id');
        $households = $this->query->table('households')->whereIn('id', $subjects->pluck('kk_id'))->select('id', 'code')->get()->keyBy('id');
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '';
        $candidates = collect();
        if ($event->kind === 'DEDOSAN' && $event->status === 'DRAFT' && $search !== '') {
            $candidates = $this->query->table('krama')->where('status', 'ACTIVE')
                ->whereIn('household_id', $this->query->table('households')->where('status', 'ACTIVE')->select('id'))
                ->whereNotIn('household_id', $subjects->pluck('kk_id'))
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->select('id', 'name', 'household_id')->orderBy('name')->limit(25)->get();
            $households = $households->union($this->query->table('households')->whereIn('id', $candidates->pluck('household_id'))->select('id', 'code')->get()->keyBy('id'));
        }
        $bills = $this->query->table('bills')->where('billing_event_id', $id)->orderBy('id')->paginate(20)->withQueryString();
        $unit = $event->unit_id ? $this->query->table('organizational_units')->where('id', $event->unit_id)->first() : null;
        $targetCount = $event->kind === 'DEDOSAN' ? $subjects->count() : $this->query->table('households')->where('status', 'ACTIVE')
            ->when($event->target === 'UNIT', fn ($q) => $q->whereIn('id', $this->query->table('household_unit_memberships')->where('unit_id', $event->unit_id)->select('household_id')))->count();

        return view('finance.event', compact('event', 'subjects', 'people', 'households', 'candidates', 'search', 'bills', 'unit', 'targetCount'));
    }

    public function bills(Request $request)
    {
        $this->access->authorize($request->user());
        $filters = $request->validate(['status' => ['nullable', Rule::in(['UNPAID', 'PAID', 'VOID'])], 'q' => ['nullable', 'string', 'max:80']]);
        $rows = $this->query->table('bills');
        if (! empty($filters['status'])) {
            $rows->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $rows->where('household_code', 'like', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
        $bills = $rows->orderByDesc('id')->paginate(20)->withQueryString();
        $events = $this->query->table('billing_events')->whereIn('id', $bills->pluck('billing_event_id'))->get()->keyBy('id');

        return view('finance.bills', compact('bills', 'events', 'filters'));
    }

    public function bill(Request $request, int $id)
    {
        $this->access->authorize($request->user());
        $bill = $this->query->table('bills')->where('id', $id)->first();
        abort_unless($bill, 404);

        return view('finance.bill', ['bill' => $bill, 'event' => $this->query->table('billing_events')->where('id', $bill->billing_event_id)->first(),
            'accounts' => $this->query->table('cash_accounts')->orderBy('name')->get(),
            'settlements' => $this->query->table('bill_settlements')->where('bill_id', $id)->orderByDesc('id')->get()]);
    }

    public function cash(Request $request)
    {
        $this->access->authorize($request->user());
        $filters = $request->validate(['account' => ['nullable', 'integer']]);
        $rows = $this->query->table('cash_transactions');
        if (! empty($filters['account'])) {
            abort_unless($this->query->table('cash_accounts')->where('id', $filters['account'])->exists(), 404);
            $rows->where('cash_account_id', $filters['account']);
        }
        $rows = $rows->orderByDesc('transaction_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('finance.cash', ['rows' => $rows, 'accounts' => $this->query->table('cash_accounts')->orderBy('name')->get()->keyBy('id'), 'filters' => $filters,
            'reversed' => $this->query->table('cash_transactions')->whereIn('reversal_of_id', $rows->pluck('id'))->pluck('reversal_of_id')->all()]);
    }

    public function report(Request $request)
    {
        $this->access->authorize($request->user());
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31']]);
        $from = $filters['from'] ?? now('Asia/Makassar')->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now('Asia/Makassar')->toDateString();
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir harus sama atau setelah tanggal awal.']);
        }

        return DB::transaction(function () use ($from, $to) {
            // MySQL REPEATABLE READ keeps the report on one committed snapshot.
            $accounts = $this->query->table('cash_accounts')->orderBy('name')->get()->map(function ($row) use ($from, $to) {
                $base = $this->query->table('cash_transactions')->where('cash_account_id', $row->id);
                $row->opening = (int) (clone $base)->where('transaction_date', '<', $from)->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN CAST(amount AS SIGNED) ELSE -CAST(amount AS SIGNED) END), 0) AS total")->value('total');
                $period = $base->whereBetween('transaction_date', [$from, $to]);
                $row->incoming = (int) (clone $period)->where('direction', 'IN')->sum('amount');
                $row->outgoing = (int) (clone $period)->where('direction', 'OUT')->sum('amount');
                $row->closing = $row->opening + $row->incoming - $row->outgoing;

                return $row;
            });
            $billing = $this->query->table('bills')->select('kind', 'status')->selectRaw('COUNT(*) AS count, SUM(gross_amount) AS total')->groupBy('kind', 'status')->get();

            return view('finance.report', compact('accounts', 'billing', 'from', 'to'));
        });
    }

    public function storeAccount(Request $request)
    {
        $this->manager->account($request->user(), $request->all());

        return back()->with('success', 'Kas ditambahkan. Catat saldo awal sebagai pemasukan.');
    }

    public function storeType(Request $request)
    {
        $this->manager->billingType($request->user(), $request->all());

        return back()->with('success', 'Jenis tagihan ditambahkan.');
    }

    public function toggleType(Request $request, int $id)
    {
        $this->manager->toggleType($request->user(), $id);

        return back()->with('success', 'Status jenis tagihan diperbarui.');
    }

    public function policy(Request $request)
    {
        $this->manager->policy($request->user(), $request->all());

        return back()->with('success', 'Aturan disimpan untuk event baru.');
    }

    public function storeEvent(Request $request)
    {
        $id = $this->manager->event($request->user(), $request->all());

        return redirect('/admin/finance/events/'.$id)->with('success', 'Draf event dibuat. Periksa sebelum menerbitkan.');
    }

    public function subject(Request $request, int $id)
    {
        $this->manager->subject($request->user(), $id, $request->all());

        return back()->with('success', 'Individu ditambahkan untuk diperiksa.');
    }

    public function removeSubject(Request $request, int $id, int $subject)
    {
        $this->manager->removeSubject($request->user(), $id, $subject);

        return back()->with('success', 'Individu dihapus dari draf.');
    }

    public function review(Request $request, int $id)
    {
        $this->manager->review($request->user(), $id, $request->all());

        return back()->with('success', 'Keputusan pemeriksaan tersimpan.');
    }

    public function issue(Request $request, int $id)
    {
        $this->manager->issue($request->user(), $id);

        return back()->with('success', 'Tagihan event telah diterbitkan.');
    }

    public function void(Request $request, int $id)
    {
        $this->manager->void($request->user(), $id, $request->all());

        return back()->with('success', 'Event dibatalkan; riwayat tetap tersedia.');
    }

    public function storeCash(Request $request)
    {
        $this->manager->cash($request->user(), $request->all());

        return redirect('/admin/finance/cash')->with('success', 'Transaksi kas tercatat.');
    }

    public function settle(Request $request, int $id)
    {
        $this->manager->settle($request->user(), $id, $request->all());

        return back()->with('success', 'Pelunasan dan pemasukan kas tercatat.');
    }

    public function reverse(Request $request, int $id)
    {
        $this->manager->reverse($request->user(), $id, $request->all());

        return back()->with('success', 'Transaksi pembalik tercatat. Pelunasan terkait, jika ada, dibuka kembali.');
    }
}

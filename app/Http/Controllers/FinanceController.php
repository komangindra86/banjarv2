<?php

namespace App\Http\Controllers;

use App\Modules\Finance\DedosanSelectionStore;
use App\Modules\Finance\FinanceAccess;
use App\Modules\Finance\FinanceManager;
use App\Modules\Finance\FinanceReportManager;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
        $this->access->authorizeAny($request->user(), ['finance.view', 'billing.issue', 'dedosan.issue']);

        return view('finance.settings', ['types' => $this->query->table('billing_types')->orderBy('name')->paginate(20), 'policy' => $this->query->table('banjar_settings')->value('dedosan_requires_approval'),
            'categories' => $this->query->table('cash_transaction_categories')->orderBy('kind')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    // Pintu masuk Kesinoman (billing.issue/dedosan.issue tanpa finance.view): daftar event beserta yang
    // perlu ditindaklanjuti, tanpa membuka buku kas/saldo yang tetap khusus finance.view.
    public function events(Request $request)
    {
        $this->access->authorizeAny($request->user(), ['finance.view', 'billing.issue', 'dedosan.issue']);
        $ready = $this->query->table('billing_events')
            ->where(fn ($q) => $q->where('status', 'APPROVED')->orWhere(fn ($q) => $q->where('status', 'DRAFT')->where('requires_approval', false)))
            ->orderByDesc('event_date')->orderByDesc('id')->get();
        $waiting = $this->query->table('billing_events')->where('status', 'DRAFT')->where('requires_approval', true)->orderByDesc('event_date')->orderByDesc('id')->get();
        $history = $this->query->table('billing_events')->whereIn('status', ['ISSUED', 'REJECTED', 'VOID'])->orderByDesc('event_date')->orderByDesc('id')->paginate(20);

        return view('finance.events', compact('ready', 'waiting', 'history'));
    }

    public function createEvent(Request $request)
    {
        $this->access->authorize($request->user(), 'billing.issue');
        $request->validate(['type' => ['required', 'integer'], 'subject' => ['nullable', 'integer']]);
        $type = $this->query->table('billing_types')->where('id', $request->integer('type'))->where('is_active', true)->first();
        abort_unless($type, 404);
        $units = $this->query->table('organizational_units')->where('is_active', true)->orderBy('name')->get();
        $scope = $this->access->unitIds($request->user(), 'billing.issue');
        if ($scope !== null) {
            $units = $units->whereIn('id', $scope)->values();
        }
        // Supaya Kesinoman tidak perlu mengarang judul/kode setiap kali: judul & referensi terisi otomatis,
        // tetap bisa diubah lewat "Gunakan kode referensi khusus" bila memang perlu.
        $now = now('Asia/Makassar');
        // Patus dipicu oleh peristiwa kematian, bukan periode bulanan seperti iuran — datang dari tautan
        // "Tetapkan patus" di halaman krama (subject = krama yang meninggal), judul menyebut nama almarhum/ah.
        $subjectName = $type->kind === 'PATUS' && $request->filled('subject')
            ? $this->query->table('krama')->where('id', $request->integer('subject'))->value('name') : null;
        $suggestedTitle = match (true) {
            $subjectName !== null => $type->name.' - '.$subjectName,
            $type->kind === 'PATUS' => $type->name.' - '.$now->translatedFormat('d F Y'),
            default => $type->name.' '.$now->translatedFormat('F Y'),
        };
        $suggestedReference = strtoupper($type->kind).'-'.$now->format('Ymd-His');
        // Tanggal penetapan default hari ini (bisa diubah, mis. tanggal kematian sebenarnya bila beda
        // dari tanggal pencatatan) — ini info yang dipakai Banjar, bukan tanggal jatuh tempo.
        $suggestedEventDate = $now->toDateString();

        return view('finance.create-event', ['type' => $type, 'units' => $units, 'scoped' => $scope !== null, 'suggestedTitle' => $suggestedTitle, 'suggestedReference' => $suggestedReference, 'suggestedEventDate' => $suggestedEventDate]);
    }

    public function event(Request $request, int $id)
    {
        $this->access->authorizeAny($request->user(), ['finance.view', 'billing.issue', 'dedosan.issue']);
        $event = $this->query->table('billing_events')->where('id', $id)->first();
        abort_unless($event, 404);
        $subjects = $this->query->table('billing_event_subjects')->where('billing_event_id', $id)->get();
        $people = $this->query->table('krama')->whereIn('id', $subjects->pluck('subject_krama_id'))->select('id', 'name')->get()->keyBy('id');
        $households = $this->query->table('households')->whereIn('id', $subjects->pluck('kk_id'))->select('id', 'code')->get()->keyBy('id');
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '';
        $candidates = collect();
        if ($event->kind === 'DEDOSAN' && $event->status === 'DRAFT' && $search !== '') {
            // Dedosan hanya dikenakan ke kepala keluarga: pencarian individu dibatasi ke kepala keluarga aktif.
            $candidates = $this->query->table('krama')->where('status', 'ACTIVE')
                ->whereIn('id', $this->query->table('households')->where('status', 'ACTIVE')->whereNotNull('head_krama_id')->select('head_krama_id'))
                ->whereNotIn('household_id', $subjects->pluck('kk_id'))
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->select('id', 'name', 'household_id')->orderBy('name')->limit(25)->get();
            $households = $households->union($this->query->table('households')->whereIn('id', $candidates->pluck('household_id'))->select('id', 'code')->get()->keyBy('id'));
        }
        $bills = $this->query->table('bills')->where('billing_event_id', $id)->orderBy('id')->paginate(20)->withQueryString();
        $unit = $event->unit_id ? $this->query->table('organizational_units')->where('id', $event->unit_id)->first() : null;
        $liveTarget = $event->kind === 'DEDOSAN' ? null : $this->manager->liveTargetCount($event);

        return view('finance.event', compact('event', 'subjects', 'people', 'households', 'candidates', 'search', 'bills', 'unit', 'liveTarget'));
    }

    public function refreshTarget(Request $request, int $id)
    {
        $counts = $this->manager->refreshTarget($request->user(), $id);

        return back()->with('success', 'Pratinjau diperbarui: '.$counts['target_kk_count'].' KK, total '.FinanceManager::money($counts['target_total_amount']).'.');
    }

    private function candidateQuery(Request $request, int $eventId, array $filters)
    {
        $scope = $this->access->unitIds($request->user(), 'dedosan.issue');
        $existingKk = $this->query->table('billing_event_subjects')->where('billing_event_id', $eventId)->pluck('kk_id');
        // Dedosan hanya dikenakan ke kepala keluarga: checklist hanya menampilkan kepala keluarga aktif.
        $rows = $this->query->table('krama')->where('krama.status', 'ACTIVE')
            ->join('households as h', 'h.id', '=', 'krama.household_id')->where('h.status', 'ACTIVE')->whereColumn('h.head_krama_id', 'krama.id');
        if ($scope !== null) {
            $rows->whereIn('krama.household_id', $this->query->table('household_unit_memberships')->whereIn('unit_id', $scope)->select('household_id'));
        }
        if (! empty($filters['q'])) {
            $rows->where('krama.name', 'like', '%'.addcslashes($filters['q'], '%_\\').'%');
        }
        if (! empty($filters['unit_id'])) {
            $rows->whereIn('krama.household_id', $this->query->table('household_unit_memberships')->where('unit_id', $filters['unit_id'])->select('household_id'));
        }

        return [$rows, $existingKk, $scope];
    }

    public function candidates(Request $request, int $id)
    {
        $this->access->authorize($request->user(), 'dedosan.issue');
        $event = $this->query->table('billing_events')->where('id', $id)->first();
        abort_unless($event, 404);
        abort_unless($event->kind === 'DEDOSAN' && $event->status === 'DRAFT', 409);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'unit_id' => ['nullable', 'integer']]);
        [$rows, $existingKk] = $this->candidateQuery($request, $id, $filters);
        $people = (clone $rows)->select('krama.id', 'krama.name', 'krama.household_id', 'h.code as household_code')->orderBy('h.code')->orderBy('krama.name')->paginate(40)->withQueryString();
        $selected = app(DedosanSelectionStore::class)->ids($id, $request->user()->id);
        $units = $this->query->table('organizational_units')->where('is_active', true)->orderBy('name')->get();
        $summary = $this->selectionSummary($id, $selected);

        return view('finance.candidates', compact('event', 'people', 'filters', 'selected', 'existingKk', 'units', 'summary'));
    }

    private function selectionSummary(int $eventId, array $ids): array
    {
        $event = $this->query->table('billing_events')->where('id', $eventId)->first();
        $people = $this->query->table('krama')->whereIn('id', $ids ?: [0])->where('status', 'ACTIVE')->get(['id', 'household_id']);

        return ['count_people' => $people->count(), 'count_kk' => $people->pluck('household_id')->unique()->count(), 'total_amount' => $people->pluck('household_id')->unique()->count() * (int) ($event->amount ?? 0)];
    }

    public function toggleCandidate(Request $request, int $id)
    {
        $this->access->authorize($request->user(), 'dedosan.issue');
        $data = $request->validate(['krama_id' => ['required', 'integer'], 'checked' => ['required', 'boolean']]);
        $ids = app(DedosanSelectionStore::class)->toggle($id, $request->user()->id, (int) $data['krama_id'], (bool) $data['checked']);

        return response()->json($this->selectionSummary($id, $ids));
    }

    public function selectPageCandidates(Request $request, int $id)
    {
        $this->access->authorize($request->user(), 'dedosan.issue');
        $data = $request->validate(['krama_ids' => ['required', 'array'], 'krama_ids.*' => ['integer']]);
        $ids = app(DedosanSelectionStore::class)->add($id, $request->user()->id, $data['krama_ids']);

        return response()->json($this->selectionSummary($id, $ids));
    }

    public function selectFilteredCandidates(Request $request, int $id)
    {
        $this->access->authorize($request->user(), 'dedosan.issue');
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'unit_id' => ['nullable', 'integer']]);
        [$rows, $existingKk] = $this->candidateQuery($request, $id, $filters);
        $rows->whereNotIn('krama.household_id', $existingKk->all() ?: [0]);
        if ((clone $rows)->count() > 2000) {
            return response()->json(['message' => 'Hasil filter lebih dari 2000 orang. Persempit pencarian sebelum memilih semua hasil filter.'], 422);
        }
        $ids = app(DedosanSelectionStore::class)->add($id, $request->user()->id, $rows->pluck('krama.id')->all());

        return response()->json($this->selectionSummary($id, $ids));
    }

    public function clearCandidates(Request $request, int $id)
    {
        $this->access->authorize($request->user(), 'dedosan.issue');
        app(DedosanSelectionStore::class)->clear($id, $request->user()->id);

        return response()->json(['count_people' => 0, 'count_kk' => 0, 'total_amount' => 0]);
    }

    public function reviewCandidates(Request $request, int $id)
    {
        $user = $request->user();
        $this->access->authorize($user, 'dedosan.issue');
        $event = $this->query->table('billing_events')->where('id', $id)->first();
        abort_unless($event, 404);
        $ids = app(DedosanSelectionStore::class)->ids($id, $user->id);
        $scope = $this->access->unitIds($user, 'dedosan.issue');
        $existingKk = $this->query->table('billing_event_subjects')->where('billing_event_id', $id)->pluck('kk_id');
        $people = $this->query->table('krama')->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        $households = $this->query->table('households')->whereIn('id', $people->pluck('household_id')->unique())->get()->keyBy('id');
        $invalid = [];
        $byHousehold = [];
        foreach ($ids as $kramaId) {
            $person = $people->get($kramaId);
            if (! $person || $person->status !== 'ACTIVE') {
                $invalid[] = ['id' => $kramaId, 'name' => $person->name ?? ('#'.$kramaId), 'reason' => 'Tidak aktif atau tidak ditemukan.'];

                continue;
            }
            $household = $households->get($person->household_id);
            if (! $household || $household->status !== 'ACTIVE') {
                $invalid[] = ['id' => $kramaId, 'name' => $person->name, 'reason' => 'KK tidak aktif.'];

                continue;
            }
            if ($scope !== null && ! $this->query->table('household_unit_memberships')->where('household_id', $household->id)->whereIn('unit_id', $scope)->exists()) {
                $invalid[] = ['id' => $kramaId, 'name' => $person->name, 'reason' => 'Di luar wilayah akses Anda.'];

                continue;
            }
            if ($existingKk->contains($household->id)) {
                $invalid[] = ['id' => $kramaId, 'name' => $person->name, 'reason' => 'KK '.$household->code.' sudah punya subjek di event ini.'];

                continue;
            }
            $byHousehold[$household->id][] = ['id' => $kramaId, 'name' => $person->name, 'household_code' => $household->code];
        }
        $valid = [];
        $conflicts = [];
        foreach ($byHousehold as $entries) {
            if (count($entries) > 1) {
                $conflicts[] = ['household_code' => $entries[0]['household_code'], 'options' => $entries];
            } else {
                $valid[] = $entries[0];
            }
        }

        return view('finance.candidates-review', compact('event', 'valid', 'conflicts', 'invalid'));
    }

    public function bulkSubjects(Request $request, int $id)
    {
        // Baris tanpa konflik datang sebagai krama_ids[] tersembunyi; setiap KK berkonflik menambah
        // tepat satu id terpilih dari radio-group conflicts[] (satu penanggung jawab per KK).
        $ids = array_map('intval', (array) $request->input('krama_ids', []));
        foreach ((array) $request->input('conflicts', []) as $choice) {
            if ($choice !== null && $choice !== '') {
                $ids[] = (int) $choice;
            }
        }
        $this->manager->bulkSubjects($request->user(), $id, ['krama_ids' => $ids, 'reason' => $request->input('reason')]);
        app(DedosanSelectionStore::class)->clear($id, $request->user()->id);

        return redirect('/admin/finance/events/'.$id)->with('success', 'Dedosan ditetapkan untuk warga terpilih.');
    }

    public function bills(Request $request)
    {
        $this->access->authorizeAny($request->user(), ['finance.view', 'billing.issue', 'dedosan.issue']);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['UNPAID', 'PAID', 'VOID', 'ALL'])], 'kind' => ['nullable', Rule::in(array_keys(FinanceManager::KINDS))],
            'q' => ['nullable', 'string', 'max:80'], 'kas' => ['nullable', 'integer']]);
        // Tampilan kerja Petengen: default hanya yang belum lunas, karena itu yang dicari saat warga datang membayar.
        $filters['status'] ??= 'UNPAID';
        $rows = $this->query->table('bills')
            ->leftJoin('households as h', 'h.id', '=', 'bills.kk_id')
            ->leftJoin('krama as head', 'head.id', '=', 'h.head_krama_id')
            ->select('bills.*', 'head.name as head_name');
        if ($filters['status'] !== 'ALL') {
            $rows->where('bills.status', $filters['status']);
        }
        if (! empty($filters['kind'])) {
            $rows->where('bills.kind', $filters['kind']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $rows->where(fn ($q) => $q->where('bills.household_code', 'like', $term)->orWhere('head.name', 'like', $term));
        }
        $bills = $rows->orderBy('bills.household_code')->orderByDesc('bills.id')->paginate(30)->withQueryString();
        $events = $this->query->table('billing_events')->whereIn('id', $bills->pluck('billing_event_id'))->get()->keyBy('id');
        $accounts = $this->query->table('cash_accounts')->orderByRaw("type = 'CASH' DESC")->orderBy('name')->get();
        $account = $accounts->firstWhere('id', (int) ($filters['kas'] ?? 0)) ?? $accounts->first();
        // Informasional: QRIS yang masih diproses untuk tagihan ini (lihat catatan di FinanceManager::settle()).
        $pendingQris = DB::table('payments')->where('banjar_id', app(CurrentBanjar::class)->id())->whereIn('bill_id', $bills->pluck('id'))
            ->whereIn('status', ['CREATING', 'UNKNOWN', 'PENDING'])->pluck('bill_id')->all();

        return view('finance.bills', compact('bills', 'events', 'filters', 'accounts', 'account', 'pendingQris'));
    }

    // Warga datang membayar tunai: satu klik "Lunas" dari daftar tagihan. Tanggal (hari ini) dan nomor
    // bukti dibuat server; submission_key dari form membuat klik ganda tidak mencatat dua kali.
    public function payCash(Request $request, int $id)
    {
        $data = $request->validate(['cash_account_id' => ['required', 'integer'], 'submission_key' => ['required', 'uuid']]);
        $bill = $this->query->table('bills')->where('id', $id)->first();
        abort_unless($bill, 404);
        $this->manager->settle($request->user(), $id, $data + ['transaction_date' => now('Asia/Makassar')->toDateString(), 'reference' => 'TUNAI-'.$bill->household_code.'-'.$id]);
        $head = $this->query->table('households')->join('krama', 'krama.id', '=', 'households.head_krama_id')->where('households.id', $bill->kk_id)->value('krama.name');

        return back()->with('success', $bill->household_code.($head ? ' · '.$head : '').' lunas '.FinanceManager::money($bill->gross_amount).' — tercatat sebagai pemasukan kas.');
    }

    public function bill(Request $request, int $id)
    {
        $this->access->authorizeAny($request->user(), ['finance.view', 'billing.issue', 'dedosan.issue']);
        $bill = $this->query->table('bills')->where('id', $id)->first();
        abort_unless($bill, 404);
        // Informasional saja (tidak memblokir pelunasan tunai) — lihat catatan di FinanceManager::settle().
        $pendingPayment = DB::table('payments')->where('banjar_id', $bill->banjar_id)->where('bill_id', $id)->whereIn('status', ['CREATING', 'UNKNOWN', 'PENDING'])->exists();

        return view('finance.bill', ['bill' => $bill, 'event' => $this->query->table('billing_events')->where('id', $bill->billing_event_id)->first(),
            'accounts' => $this->query->table('cash_accounts')->orderBy('name')->get(), 'pendingPayment' => $pendingPayment,
            'categories' => $this->query->table('cash_transaction_categories')->where('kind', 'IN')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
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
            'categories' => $this->query->table('cash_transaction_categories')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id'),
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

    public function reports(Request $request)
    {
        // Petengen menyiapkan laporan; Kelian dan pengurus lain dengan finance.view dapat memeriksa.
        $this->access->authorize($request->user());
        $rows = $this->query->table('finance_reports')->orderByDesc('period_to')->orderByDesc('id')->paginate(15);

        return view('finance.reports.index', compact('rows'));
    }

    public function createReport(Request $request)
    {
        $this->access->authorize($request->user(), 'cash.manage');
        $previous = $this->query->table('finance_reports')->where('status', 'FINALIZED')->whereNull('superseded_by_id')->orderByDesc('period_to')->first();

        return view('finance.reports.create', compact('previous'));
    }

    public function storeReport(Request $request, FinanceReportManager $reports)
    {
        $id = $reports->create($request->user(), $request->all());

        return redirect('/admin/finance/reports/'.$id)->with('success', 'Draf laporan dibuat. Periksa sebelum finalisasi.');
    }

    public function reportShow(Request $request, int $id, FinanceReportManager $reports)
    {
        $this->access->authorize($request->user());
        $report = $reports->row($id);
        $data = $reports->compute($report);
        $signers = $reports->signers($request->user());
        // Draf versi koreksi: periodenya terkunci pada laporan yang dikoreksi (hanya nama yang boleh diubah).
        $isRevision = $this->query->table('finance_reports')->where('superseded_by_id', $report->id)->exists();

        return view('finance.reports.show', compact('report', 'data', 'signers', 'isRevision'));
    }

    public function finalizeReport(Request $request, int $id, FinanceReportManager $reports)
    {
        $reports->finalize($request->user(), $id, $request->all());

        return redirect('/admin/finance/reports/'.$id)->with('success', 'Laporan difinalkan. PDF sedang disiapkan dan akan tersedia sesaat lagi.');
    }

    public function updateReport(Request $request, int $id, FinanceReportManager $reports)
    {
        $reports->updateDraft($request->user(), $id, $request->all());

        return redirect('/admin/finance/reports/'.$id)->with('success', 'Draf laporan diperbarui.');
    }

    public function discardReport(Request $request, int $id, FinanceReportManager $reports)
    {
        $title = $reports->discard($request->user(), $id);

        return redirect('/admin/finance/reports')->with('success', 'Draf laporan "'.$title.'" dibatalkan. Buku kas tidak berubah.');
    }

    public function reviseReport(Request $request, int $id, FinanceReportManager $reports)
    {
        $newId = $reports->revise($request->user(), $id, $request->all());

        return redirect('/admin/finance/reports/'.$newId)->with('success', 'Versi koreksi dibuat. Versi sebelumnya tetap tersimpan sebagai riwayat.');
    }

    public function downloadReportPdf(Request $request, int $id, Audit $audit, CurrentBanjar $tenant)
    {
        $this->access->authorize($request->user());
        $report = $this->query->table('finance_reports')->where('id', $id)->where('pdf_status', 'READY')->first();
        abort_unless($report, 404, 'PDF belum tersedia.');
        $bytes = Crypt::decryptString($report->pdf_encrypted);
        abort_unless(hash_equals($report->sha256, hash('sha256', $bytes)), 409, 'Arsip perlu diperiksa oleh pengurus.');
        $audit->record($tenant->id(), $request->user()->id, 'finance.report_downloaded', 'finance_reports', $report->id);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="laporan-kas-'.$report->id.'.pdf"']);
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

    public function storeCategory(Request $request)
    {
        $this->manager->category($request->user(), $request->all());

        return back()->with('success', 'Kategori ditambahkan.');
    }

    public function quickAddCategory(Request $request)
    {
        try {
            $id = $this->manager->category($request->user(), $request->all());
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first() ?? 'Gagal menambah kategori.'], 422);
        }

        return response()->json(['id' => $id, 'name' => $this->query->table('cash_transaction_categories')->where('id', $id)->value('name')]);
    }

    public function toggleCategory(Request $request, int $id)
    {
        $this->manager->toggleCategory($request->user(), $id);

        return back()->with('success', 'Status kategori diperbarui.');
    }

    public function seedCategoryTemplate(Request $request)
    {
        $count = $this->manager->seedCategoryTemplate($request->user());

        return back()->with('success', $count > 0 ? $count.' kategori templat ditambahkan.' : 'Semua kategori templat sudah ada.');
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

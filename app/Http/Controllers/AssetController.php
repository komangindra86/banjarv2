<?php

namespace App\Http\Controllers;

use App\Modules\Asset\AssetAccess;
use App\Modules\Asset\AssetManager;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AssetController extends Controller
{
    public function __construct(private AssetAccess $access, private AssetManager $manager, private TenantQuery $query) {}

    public function index(Request $request)
    {
        $this->access->authorize($request->user());
        $filters = $request->validate(['status' => ['nullable', Rule::in(['ACTIVE', 'DISPOSED'])], 'category' => ['nullable', 'integer'], 'location' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);
        $rows = $this->query->table('assets');
        $rows->where('status', $filters['status'] ?? 'ACTIVE');
        if (! empty($filters['category'])) {
            $rows->where('category_id', $filters['category']);
        }
        if (! empty($filters['location'])) {
            $rows->where('location_id', $filters['location']);
        }
        if (! empty($filters['q'])) {
            $needle = '%'.addcslashes($filters['q'], '%_\\').'%';
            $rows->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('code', 'like', $needle));
        }
        $assets = $rows->orderBy('name')->paginate(20)->withQueryString();
        $categories = $this->query->table('asset_categories')->orderBy('name')->get();
        $locations = $this->query->table('asset_locations')->orderBy('name')->get()->keyBy('id');

        return view('asset.index', compact('assets', 'categories', 'locations', 'filters'));
    }

    public function create(Request $request)
    {
        $this->access->authorize($request->user(), 'asset.manage');

        return view('asset.form', ['asset' => null, 'categories' => $this->query->table('asset_categories')->where('is_active', true)->orderBy('name')->get(), 'locations' => $this->query->table('asset_locations')->where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $id = $this->manager->create($request->user(), $request->all());

        return redirect('/admin/assets/'.$id)->with('success', 'Aset ditambahkan ke inventaris.');
    }

    public function edit(Request $request, int $id)
    {
        $this->access->authorize($request->user(), 'asset.manage');
        $asset = $this->query->table('assets')->where('id', $id)->first();
        abort_unless($asset, 404);

        return view('asset.form', ['asset' => $asset, 'categories' => $this->query->table('asset_categories')->where('is_active', true)->orderBy('name')->get(), 'locations' => collect()]);
    }

    public function update(Request $request, int $id)
    {
        $this->manager->update($request->user(), $id, $request->all());

        return redirect('/admin/assets/'.$id)->with('success', 'Aset diperbarui.');
    }

    public function show(Request $request, int $id)
    {
        $this->access->authorize($request->user());
        $asset = $this->query->table('assets')->where('id', $id)->first();
        abort_unless($asset, 404);
        $category = $this->query->table('asset_categories')->where('id', $asset->category_id)->first();
        $location = $this->query->table('asset_locations')->where('id', $asset->location_id)->first();
        $movements = $this->query->table('asset_movements')->where('asset_id', $id)->orderByDesc('id')->limit(30)->get();
        $inspections = $this->query->table('asset_inspections')->where('asset_id', $id)->orderByDesc('id')->limit(10)->get();
        $maintenance = $this->query->table('asset_maintenance')->where('asset_id', $id)->orderByDesc('id')->get();
        $loanItems = $this->query->table('asset_loan_items')->where('asset_id', $id)->whereColumn('returned_quantity', '<', 'quantity')->orderByDesc('id')->get();
        $loans = $this->query->table('asset_loans')->whereIn('id', $loanItems->pluck('loan_id'))->get()->keyBy('id');
        $locations = $this->query->table('asset_locations')->where('is_active', true)->where('id', '<>', $asset->location_id)->orderBy('name')->get();
        $cashOptions = $this->query->table('cash_transactions')->where('direction', 'OUT')->orderByDesc('id')->limit(50)->get(['id', 'reference', 'amount', 'transaction_date']);

        return view('asset.show', compact('asset', 'category', 'location', 'movements', 'inspections', 'maintenance', 'loanItems', 'loans', 'locations', 'cashOptions'));
    }

    public function storeCategory(Request $request)
    {
        $this->manager->category($request->user(), $request->all());

        return back()->with('success', 'Kategori ditambahkan.');
    }

    public function toggleCategory(Request $request, int $id)
    {
        $this->manager->toggleCategory($request->user(), $id);

        return back()->with('success', 'Status kategori diperbarui.');
    }

    public function storeLocation(Request $request)
    {
        $this->manager->location($request->user(), $request->all());

        return back()->with('success', 'Lokasi ditambahkan.');
    }

    public function toggleLocation(Request $request, int $id)
    {
        $this->manager->toggleLocation($request->user(), $id);

        return back()->with('success', 'Status lokasi diperbarui.');
    }

    public function transfer(Request $request, int $id)
    {
        $this->manager->transfer($request->user(), $id, $request->all());

        return back()->with('success', 'Aset dipindahkan.');
    }

    public function inspect(Request $request, int $id)
    {
        $this->manager->inspect($request->user(), $id, $request->all());

        return back()->with('success', 'Kondisi aset dicatat.');
    }

    public function scheduleMaintenance(Request $request, int $id)
    {
        $this->manager->scheduleMaintenance($request->user(), $id, $request->all());

        return back()->with('success', 'Perawatan dijadwalkan.');
    }

    public function completeMaintenance(Request $request, int $id)
    {
        $this->manager->completeMaintenance($request->user(), $id, $request->all());

        return back()->with('success', 'Perawatan selesai dicatat.');
    }

    public function dispose(Request $request, int $id)
    {
        $this->manager->dispose($request->user(), $id, $request->all());

        return back()->with('success', 'Penghapusan aset tercatat.');
    }

    public function loans(Request $request)
    {
        $this->access->authorize($request->user());
        $loans = $this->query->table('asset_loans')->orderByDesc('id')->paginate(20)->withQueryString();
        $borrowers = $this->query->table('krama')->whereIn('id', $loans->pluck('borrower_krama_id'))->select('id', 'name')->get()->keyBy('id');

        return view('asset.loans', compact('loans', 'borrowers'));
    }

    public function createLoan(Request $request)
    {
        $this->access->authorize($request->user(), 'asset.manage');
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '';
        $candidates = collect();
        if ($search !== '') {
            $candidates = $this->query->table('krama')->where('status', 'ACTIVE')
                ->whereIn('household_id', $this->query->table('households')->where('status', 'ACTIVE')->select('id'))
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->select('id', 'name')->orderBy('name')->limit(25)->get();
        }

        return view('asset.loan-form', compact('search', 'candidates'));
    }

    public function storeLoan(Request $request)
    {
        $id = $this->manager->loan($request->user(), $request->all());

        return redirect('/admin/assets/loans/'.$id)->with('success', 'Peminjaman dibuat. Tambahkan aset yang dipinjam.');
    }

    public function loan(Request $request, int $id)
    {
        $this->access->authorize($request->user());
        $loan = $this->query->table('asset_loans')->where('id', $id)->first();
        abort_unless($loan, 404);
        $items = $this->query->table('asset_loan_items')->where('loan_id', $id)->get();
        $assets = $this->query->table('assets')->whereIn('id', $items->pluck('asset_id'))->get()->keyBy('id');
        $borrower = $loan->borrower_krama_id ? $this->query->table('krama')->where('id', $loan->borrower_krama_id)->first() : null;
        $availableAssets = $this->query->table('assets')->where('status', 'ACTIVE')->where('quantity_available', '>', 0)->orderBy('name')->get();
        $returns = $this->query->table('asset_returns')->whereIn('loan_item_id', $items->pluck('id'))->orderByDesc('id')->get();

        return view('asset.loan', compact('loan', 'items', 'assets', 'borrower', 'availableAssets', 'returns'));
    }

    public function addLoanItem(Request $request, int $id)
    {
        $this->manager->loanItem($request->user(), $id, $request->all());

        return back()->with('success', 'Aset ditambahkan pada peminjaman.');
    }

    public function returnLoanItem(Request $request, int $id, int $item)
    {
        $this->manager->returnItem($request->user(), $id, $item, $request->all());

        return back()->with('success', 'Pengembalian tercatat.');
    }

    public function opnames(Request $request)
    {
        $this->access->authorize($request->user());
        $opnames = $this->query->table('stock_opnames')->orderByDesc('id')->paginate(20);
        $locations = $this->query->table('asset_locations')->orderBy('name')->get()->keyBy('id');

        return view('asset.opnames', compact('opnames', 'locations'));
    }

    public function createOpname(Request $request)
    {
        $this->access->authorize($request->user(), 'asset.manage');

        return view('asset.opname-form', ['locations' => $this->query->table('asset_locations')->where('is_active', true)->orderBy('name')->get()]);
    }

    public function storeOpname(Request $request)
    {
        $id = $this->manager->openOpname($request->user(), $request->all());

        return redirect('/admin/assets/opname/'.$id)->with('success', 'Stock opname dibuka. Hitung setiap aset di lokasi.');
    }

    public function opname(Request $request, int $id)
    {
        $this->access->authorize($request->user());
        $opname = $this->query->table('stock_opnames')->where('id', $id)->first();
        abort_unless($opname, 404);
        $items = $this->query->table('stock_opname_items')->where('opname_id', $id)->orderBy('id')->get();
        $assets = $this->query->table('assets')->whereIn('id', $items->pluck('asset_id'))->get()->keyBy('id');

        return view('asset.opname-show', compact('opname', 'items', 'assets'));
    }

    public function countItem(Request $request, int $id, int $item)
    {
        $this->manager->countItem($request->user(), $id, $item, $request->all());

        return back()->with('success', 'Hitungan tersimpan.');
    }

    public function completeOpname(Request $request, int $id)
    {
        $result = $this->manager->completeOpname($request->user(), $id);

        return redirect('/admin/assets/opname/'.$id)->with('success', 'Stock opname selesai. '.$result['adjusted'].' aset disesuaikan.');
    }

    public function qr(Request $request, int $id)
    {
        $this->access->authorize($request->user());
        $asset = $this->query->table('assets')->where('id', $id)->first();
        abort_unless($asset, 404);

        return view('asset.qr', ['asset' => $asset, 'assetUrl' => url('/admin/assets/'.$asset->id)]);
    }
}

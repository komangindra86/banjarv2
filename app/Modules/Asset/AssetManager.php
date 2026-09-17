<?php

namespace App\Modules\Asset;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inventory lifecycle: perolehan → lokasi → kondisi → peminjaman → perawatan → stock opname → penghapusan.
 * Assets are never hard-deleted; disposal reduces quantity to zero and flips status, history stays queryable.
 */
final class AssetManager
{
    public const KINDS = ['INDIVIDUAL' => 'Barang individual', 'QUANTITY' => 'Barang jumlah'];

    public const CONDITIONS = ['BAIK' => 'Baik', 'PERLU_PERAWATAN' => 'Perlu perawatan', 'RUSAK_RINGAN' => 'Rusak ringan', 'RUSAK_BERAT' => 'Rusak berat', 'HILANG' => 'Hilang', 'TIDAK_DIGUNAKAN' => 'Tidak digunakan'];

    public const DISPOSAL_METHODS = ['SOLD' => 'Dijual', 'DAMAGED_WRITE_OFF' => 'Rusak total', 'LOST' => 'Hilang', 'DONATED' => 'Dihibahkan', 'OTHER' => 'Lainnya'];

    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private AssetAccess $access, private Audit $audit) {}

    private function fail(string $message, string $field = 'asset'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function write(User $actor, string $permission, callable $work): mixed
    {
        $this->access->authorize($actor, $permission);

        return DB::transaction(function () use ($actor, $permission, $work) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->first();
            $this->access->authorize($actor, $permission);

            return $work();
        }, 3);
    }

    private function insert(string $table, array $data): int
    {
        return DB::table($table)->insertGetId($data + ['banjar_id' => $this->tenant->id(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function row(string $table, int $id): object
    {
        $row = $this->query->table($table)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function lockedAsset(int $id): object
    {
        $row = $this->query->table('assets')->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
    }

    private function audit(User $actor, string $action, string $table, int $id, array $data = []): void
    {
        $this->audit->record($this->tenant->id(), $actor->id, $action, $table, $id, [], $data);
    }

    private function movement(User $actor, int $assetId, string $type, int $quantity, ?int $from = null, ?int $to = null, ?string $note = null): void
    {
        $this->insert('asset_movements', ['asset_id' => $assetId, 'type' => $type, 'quantity' => $quantity, 'from_location_id' => $from, 'to_location_id' => $to, 'note' => $note, 'actor_id' => $actor->id]);
    }

    // --- Categories & locations -------------------------------------------------

    public function category(User $actor, array $input): int
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100']])->validate();

        return $this->write($actor, 'asset.manage', function () use ($actor, $data) {
            if ($this->query->table('asset_categories')->where('name', $data['name'])->exists()) {
                $this->fail('Nama kategori sudah digunakan.', 'name');
            }
            $id = $this->insert('asset_categories', $data);
            $this->audit($actor, 'asset.category_created', 'asset_categories', $id, ['name' => $data['name']]);

            return $id;
        });
    }

    public function toggleCategory(User $actor, int $id): void
    {
        $this->write($actor, 'asset.manage', function () use ($actor, $id) {
            $row = $this->row('asset_categories', $id);
            $this->query->table('asset_categories')->where('id', $id)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);
            $this->audit($actor, 'asset.category_toggled', 'asset_categories', $id, ['status' => $row->is_active ? 'INACTIVE' : 'ACTIVE']);
        });
    }

    public function location(User $actor, array $input): int
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100']])->validate();

        return $this->write($actor, 'asset.manage', function () use ($actor, $data) {
            if ($this->query->table('asset_locations')->where('name', $data['name'])->exists()) {
                $this->fail('Nama lokasi sudah digunakan.', 'name');
            }
            $id = $this->insert('asset_locations', $data);
            $this->audit($actor, 'asset.location_created', 'asset_locations', $id, ['name' => $data['name']]);

            return $id;
        });
    }

    public function toggleLocation(User $actor, int $id): void
    {
        $this->write($actor, 'asset.manage', function () use ($actor, $id) {
            $row = $this->row('asset_locations', $id);
            $this->query->table('asset_locations')->where('id', $id)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);
            $this->audit($actor, 'asset.location_toggled', 'asset_locations', $id, ['status' => $row->is_active ? 'INACTIVE' : 'ACTIVE']);
        });
    }

    // --- Assets -------------------------------------------------------------

    public function create(User $actor, array $input): int
    {
        $data = Validator::make($input, [
            'category_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))], 'code' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,39}$/'],
            'name' => ['required', 'string', 'max:150'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'acquisition_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now('Asia/Makassar')->toDateString()],
            'acquisition_cost' => ['nullable', 'regex:/^[0-9]{1,12}$/'], 'acquisition_cash_transaction_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['category_id' => 'kategori', 'location_id' => 'lokasi'])->validate();

        return $this->write($actor, 'asset.manage', function () use ($actor, $data) {
            $category = $this->row('asset_categories', (int) $data['category_id']);
            $location = $this->row('asset_locations', (int) $data['location_id']);
            if (! $category->is_active || ! $location->is_active) {
                $this->fail('Kategori dan lokasi harus aktif.');
            }
            if ($this->query->table('assets')->where('code', $data['code'])->exists()) {
                $this->fail('Kode aset sudah digunakan.', 'code');
            }
            $quantity = $data['kind'] === 'INDIVIDUAL' ? 1 : (int) ($data['quantity'] ?? 1);
            $cashId = null;
            if (! empty($data['acquisition_cash_transaction_id'])) {
                $cashId = $this->row('cash_transactions', (int) $data['acquisition_cash_transaction_id'])->id;
            }
            $id = $this->insert('assets', [
                'category_id' => $category->id, 'location_id' => $location->id, 'kind' => $data['kind'], 'code' => $data['code'], 'name' => $data['name'],
                'quantity' => $quantity, 'acquisition_date' => $data['acquisition_date'] ?? null, 'acquisition_cost' => $data['acquisition_cost'] ?? null,
                'acquisition_cash_transaction_id' => $cashId, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
            ]);
            $this->movement($actor, $id, 'ACQUIRED', $quantity, null, $location->id);
            $this->audit($actor, 'asset.created', 'assets', $id, ['category_id' => $category->id, 'location_id' => $location->id, 'kind' => $data['kind'], 'quantity' => $quantity]);

            return $id;
        });
    }

    public function update(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, [
            'category_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:150'],
            'acquisition_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now('Asia/Makassar')->toDateString()],
            'acquisition_cost' => ['nullable', 'regex:/^[0-9]{1,12}$/'], 'acquisition_cash_transaction_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['category_id' => 'kategori'])->validate();
        $this->write($actor, 'asset.manage', function () use ($actor, $id, $data) {
            $asset = $this->row('assets', $id);
            abort_if($asset->status === 'DISPOSED', 409, 'Aset sudah dihapuskan.');
            $category = $this->row('asset_categories', (int) $data['category_id']);
            if (! $category->is_active) {
                $this->fail('Kategori harus aktif.', 'category_id');
            }
            $cashId = null;
            if (! empty($data['acquisition_cash_transaction_id'])) {
                $cashId = $this->row('cash_transactions', (int) $data['acquisition_cash_transaction_id'])->id;
            }
            $this->query->table('assets')->where('id', $id)->update([
                'category_id' => $category->id, 'name' => $data['name'], 'acquisition_date' => $data['acquisition_date'] ?? null,
                'acquisition_cost' => $data['acquisition_cost'] ?? null, 'acquisition_cash_transaction_id' => $cashId, 'notes' => $data['notes'] ?? null, 'updated_at' => now(),
            ]);
            $this->audit($actor, 'asset.updated', 'assets', $id, ['category_id' => $category->id]);
        });
    }

    public function transfer(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, ['location_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:500']], [], ['location_id' => 'lokasi'])->validate();
        $this->write($actor, 'asset.manage', function () use ($actor, $id, $data) {
            $asset = $this->lockedAsset($id);
            abort_if($asset->status === 'DISPOSED', 409, 'Aset sudah dihapuskan.');
            $location = $this->row('asset_locations', (int) $data['location_id']);
            if (! $location->is_active) {
                $this->fail('Lokasi tujuan harus aktif.', 'location_id');
            }
            if ($location->id === $asset->location_id) {
                $this->fail('Aset sudah berada di lokasi ini.', 'location_id');
            }
            if ($asset->quantity_available < 1) {
                $this->fail('Tidak ada stok tersedia untuk dipindahkan; sedang dipinjam seluruhnya.');
            }
            $this->query->table('assets')->where('id', $id)->update(['location_id' => $location->id, 'updated_at' => now()]);
            $this->movement($actor, $id, 'TRANSFER', $asset->quantity_available, $asset->location_id, $location->id, $data['note'] ?? null);
            $this->audit($actor, 'asset.transferred', 'assets', $id, ['location_id' => $location->id]);
        });
    }

    public function inspect(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, ['condition' => ['required', Rule::in(array_keys(self::CONDITIONS))], 'note' => ['nullable', 'string', 'max:500']])->validate();
        $this->write($actor, 'asset.manage', function () use ($actor, $id, $data) {
            $asset = $this->lockedAsset($id);
            abort_if($asset->status === 'DISPOSED', 409, 'Aset sudah dihapuskan.');
            $this->insert('asset_inspections', ['asset_id' => $id, 'condition' => $data['condition'], 'note' => $data['note'] ?? null, 'inspector_id' => $actor->id]);
            $this->query->table('assets')->where('id', $id)->update(['condition' => $data['condition'], 'updated_at' => now()]);
            $this->movement($actor, $id, 'CONDITION_CHANGE', $asset->quantity, null, null, $data['note'] ?? null);
            $this->audit($actor, 'asset.inspected', 'assets', $id, ['condition' => $data['condition']]);
        });
    }

    // --- Loans ----------------------------------------------------------------

    public function loan(User $actor, array $input): int
    {
        $data = Validator::make($input, [
            'borrower_krama_id' => ['nullable', 'integer'], 'borrower_name' => ['nullable', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:255'], 'loan_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:loan_date', 'before_or_equal:2100-12-31'],
        ])->validate();
        if (empty($data['borrower_krama_id']) && empty($data['borrower_name'])) {
            $this->fail('Pilih krama peminjam atau isi nama peminjam.', 'borrower_name');
        }

        return $this->write($actor, 'asset.manage', function () use ($actor, $data) {
            $borrowerId = null;
            if (! empty($data['borrower_krama_id'])) {
                $person = $this->row('krama', (int) $data['borrower_krama_id']);
                abort_unless($person->status === 'ACTIVE', 422, 'Krama peminjam harus aktif.');
                $borrowerId = $person->id;
            }
            $id = $this->insert('asset_loans', [
                'borrower_krama_id' => $borrowerId, 'borrower_name' => $data['borrower_name'] ?? null, 'purpose' => $data['purpose'],
                'loan_date' => $data['loan_date'], 'due_date' => $data['due_date'] ?? null, 'created_by' => $actor->id,
            ]);
            $this->audit($actor, 'asset.loan_created', 'asset_loans', $id, ['loan_id' => $id]);

            return $id;
        });
    }

    public function loanItem(User $actor, int $loanId, array $input): void
    {
        $data = Validator::make($input, [
            'asset_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'condition_out' => ['required', Rule::in(array_keys(self::CONDITIONS))],
        ])->validate();
        $this->write($actor, 'asset.manage', function () use ($actor, $loanId, $data) {
            $loan = $this->row('asset_loans', $loanId);
            abort_if($loan->status !== 'ACTIVE', 409, 'Peminjaman sudah selesai.');
            $asset = $this->lockedAsset((int) $data['asset_id']);
            abort_if($asset->status === 'DISPOSED', 409, 'Aset sudah dihapuskan.');
            if ($this->query->table('asset_loan_items')->where('loan_id', $loanId)->where('asset_id', $asset->id)->exists()) {
                $this->fail('Aset ini sudah ada pada peminjaman ini.', 'asset_id');
            }
            $quantity = (int) $data['quantity'];
            if ($quantity > $asset->quantity_available) {
                $this->fail('Stok tersedia hanya '.$asset->quantity_available.'.', 'quantity');
            }
            $itemId = $this->insert('asset_loan_items', ['loan_id' => $loanId, 'asset_id' => $asset->id, 'quantity' => $quantity, 'condition_out' => $data['condition_out']]);
            $this->query->table('assets')->where('id', $asset->id)->update(['quantity_on_loan' => $asset->quantity_on_loan + $quantity, 'updated_at' => now()]);
            $this->movement($actor, $asset->id, 'LOAN_OUT', $quantity, $asset->location_id, null, 'Peminjaman #'.$loanId);
            $this->audit($actor, 'asset.loan_item_added', 'asset_loan_items', $itemId, ['loan_id' => $loanId, 'asset_id' => $asset->id, 'quantity' => $quantity]);
        });
    }

    public function returnItem(User $actor, int $loanId, int $itemId, array $input): void
    {
        $data = Validator::make($input, [
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'], 'condition_in' => ['required', Rule::in(array_keys(self::CONDITIONS))],
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $this->write($actor, 'asset.manage', function () use ($actor, $loanId, $itemId, $data) {
            $loan = $this->row('asset_loans', $loanId);
            $item = $this->row('asset_loan_items', $itemId);
            abort_unless($item->loan_id === $loan->id, 404);
            $remaining = $item->quantity - $item->returned_quantity;
            $quantity = (int) $data['quantity'];
            if ($quantity > $remaining) {
                $this->fail('Sisa yang belum dikembalikan hanya '.$remaining.'.', 'quantity');
            }
            $asset = $this->lockedAsset($item->asset_id);
            $this->insert('asset_returns', ['loan_item_id' => $itemId, 'quantity' => $quantity, 'condition_in' => $data['condition_in'], 'note' => $data['note'] ?? null, 'actor_id' => $actor->id]);
            $this->query->table('asset_loan_items')->where('id', $itemId)->update(['returned_quantity' => $item->returned_quantity + $quantity, 'updated_at' => now()]);
            $this->query->table('assets')->where('id', $asset->id)->update(['quantity_on_loan' => $asset->quantity_on_loan - $quantity, 'updated_at' => now()]);
            $this->insert('asset_inspections', ['asset_id' => $asset->id, 'condition' => $data['condition_in'], 'note' => 'Dari pengembalian peminjaman #'.$loanId, 'inspector_id' => $actor->id]);
            if ($asset->kind === 'INDIVIDUAL') {
                $this->query->table('assets')->where('id', $asset->id)->update(['condition' => $data['condition_in']]);
            }
            $this->movement($actor, $asset->id, 'LOAN_RETURN', $quantity, null, $asset->location_id, $data['note'] ?? null);
            $stillOut = $this->query->table('asset_loan_items')->where('loan_id', $loanId)->selectRaw('SUM(quantity) AS total, SUM(returned_quantity) AS returned')->first();
            $status = ((int) $stillOut->returned >= (int) $stillOut->total) ? 'RETURNED' : 'PARTIALLY_RETURNED';
            $this->query->table('asset_loans')->where('id', $loanId)->update(['status' => $status, 'updated_at' => now()]);
            $this->audit($actor, 'asset.loan_item_returned', 'asset_loan_items', $itemId, ['loan_id' => $loanId, 'asset_id' => $asset->id, 'quantity' => $quantity, 'condition' => $data['condition_in']]);
        });
    }

    // --- Maintenance -----------------------------------------------------------

    public function scheduleMaintenance(User $actor, int $assetId, array $input): int
    {
        $data = Validator::make($input, [
            'description' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ])->validate();

        return $this->write($actor, 'asset.manage', function () use ($actor, $assetId, $data) {
            $asset = $this->row('assets', $assetId);
            abort_if($asset->status === 'DISPOSED', 409, 'Aset sudah dihapuskan.');
            $id = $this->insert('asset_maintenance', ['asset_id' => $assetId, 'description' => $data['description'], 'scheduled_at' => $data['scheduled_at'] ?? null, 'created_by' => $actor->id]);
            $this->audit($actor, 'asset.maintenance_scheduled', 'asset_maintenance', $id, ['asset_id' => $assetId]);

            return $id;
        });
    }

    public function completeMaintenance(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, [
            'cost' => ['nullable', 'regex:/^[0-9]{1,12}$/'], 'cash_transaction_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $this->write($actor, 'asset.manage', function () use ($actor, $id, $data) {
            $maintenance = $this->row('asset_maintenance', $id);
            abort_if($maintenance->status === 'DONE', 409, 'Perawatan sudah selesai.');
            $cashId = null;
            if (! empty($data['cash_transaction_id'])) {
                $cashId = $this->row('cash_transactions', (int) $data['cash_transaction_id'])->id;
            }
            $this->query->table('asset_maintenance')->where('id', $id)->update([
                'status' => 'DONE', 'cost' => $data['cost'] ?? null, 'cash_transaction_id' => $cashId, 'note' => $data['note'] ?? null,
                'completed_by' => $actor->id, 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'asset.maintenance_completed', 'asset_maintenance', $id, ['asset_id' => $maintenance->asset_id]);
        });
    }

    // --- Stock opname ------------------------------------------------------------

    public function openOpname(User $actor, array $input): int
    {
        $data = Validator::make($input, ['location_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:500']])->validate();

        return $this->write($actor, 'asset.manage', function () use ($actor, $data) {
            $locationId = null;
            if (! empty($data['location_id'])) {
                $locationId = $this->row('asset_locations', (int) $data['location_id'])->id;
            }
            $id = $this->insert('stock_opnames', ['location_id' => $locationId, 'note' => $data['note'] ?? null, 'started_by' => $actor->id]);
            $assets = $this->query->table('assets')->where('status', 'ACTIVE')->when($locationId, fn ($q) => $q->where('location_id', $locationId))->get(['id', 'quantity_available']);
            foreach ($assets->chunk(500) as $chunk) {
                $rows = $chunk->map(fn ($a) => ['banjar_id' => $this->tenant->id(), 'opname_id' => $id, 'asset_id' => $a->id, 'expected_quantity' => $a->quantity_available, 'created_at' => now(), 'updated_at' => now()])->all();
                DB::table('stock_opname_items')->insert($rows);
            }
            $this->audit($actor, 'asset.opname_opened', 'stock_opnames', $id, ['location_id' => $locationId]);

            return $id;
        });
    }

    public function countItem(User $actor, int $opnameId, int $itemId, array $input): void
    {
        $data = Validator::make($input, ['counted_quantity' => ['required', 'integer', 'min:0', 'max:999999'], 'note' => ['nullable', 'string', 'max:500']])->validate();
        $this->write($actor, 'asset.manage', function () use ($opnameId, $itemId, $data) {
            $opname = $this->row('stock_opnames', $opnameId);
            abort_if($opname->status === 'COMPLETED', 409, 'Stock opname sudah selesai.');
            $item = $this->row('stock_opname_items', $itemId);
            abort_unless($item->opname_id === $opname->id, 404);
            $this->query->table('stock_opname_items')->where('id', $itemId)->update(['counted_quantity' => $data['counted_quantity'], 'note' => $data['note'] ?? null, 'updated_at' => now()]);
        });
    }

    public function completeOpname(User $actor, int $opnameId): array
    {
        return $this->write($actor, 'asset.manage', function () use ($actor, $opnameId) {
            $opname = $this->row('stock_opnames', $opnameId);
            if ($opname->status === 'COMPLETED') {
                return ['adjusted' => 0];
            }
            $items = $this->query->table('stock_opname_items')->where('opname_id', $opnameId)->get();
            $pending = $items->whereNull('counted_quantity')->count();
            if ($pending) {
                $this->fail($pending.' aset belum dihitung. Lengkapi hitungan sebelum menyelesaikan.');
            }
            $adjusted = 0;
            foreach ($items as $item) {
                $delta = (int) $item->counted_quantity - (int) $item->expected_quantity;
                if ($delta === 0) {
                    continue;
                }
                $asset = $this->lockedAsset($item->asset_id);
                $newQuantity = $asset->quantity + $delta;
                if ($newQuantity < $asset->quantity_on_loan) {
                    $this->fail('Penyesuaian aset "'.$asset->name.'" membuat jumlah kurang dari yang sedang dipinjam. Periksa data peminjaman terlebih dahulu.');
                }
                $this->query->table('assets')->where('id', $asset->id)->update(['quantity' => $newQuantity, 'updated_at' => now()]);
                $this->movement($actor, $asset->id, 'ADJUSTMENT', abs($delta), null, null, 'Stock opname #'.$opnameId.($delta > 0 ? ' (tambah)' : ' (kurang)'));
                $this->audit($actor, 'asset.opname_adjusted', 'assets', $asset->id, ['quantity' => $newQuantity, 'opname_id' => $opnameId]);
                $adjusted++;
            }
            $this->query->table('stock_opnames')->where('id', $opnameId)->update(['status' => 'COMPLETED', 'completed_by' => $actor->id, 'completed_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'asset.opname_completed', 'stock_opnames', $opnameId, ['opname_id' => $opnameId]);

            return ['adjusted' => $adjusted];
        });
    }

    // --- Disposal ------------------------------------------------------------

    public function dispose(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, [
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'], 'method' => ['required', Rule::in(array_keys(self::DISPOSAL_METHODS))],
            'reason' => ['required', 'string', 'min:5', 'max:500'], 'proceeds_cash_transaction_id' => ['nullable', 'integer'],
        ])->validate();
        $this->write($actor, 'asset.dispose', function () use ($actor, $id, $data) {
            $asset = $this->lockedAsset($id);
            abort_if($asset->status === 'DISPOSED', 409, 'Aset sudah dihapuskan.');
            $quantity = (int) $data['quantity'];
            if ($quantity > $asset->quantity_available) {
                $this->fail('Stok tersedia hanya '.$asset->quantity_available.'; stok yang sedang dipinjam tidak dapat dihapuskan.', 'quantity');
            }
            $cashId = null;
            if (! empty($data['proceeds_cash_transaction_id'])) {
                $cashId = $this->row('cash_transactions', (int) $data['proceeds_cash_transaction_id'])->id;
            }
            $disposalId = $this->insert('asset_disposals', [
                'asset_id' => $id, 'quantity' => $quantity, 'method' => $data['method'], 'reason' => $data['reason'],
                'proceeds_cash_transaction_id' => $cashId, 'actor_id' => $actor->id,
            ]);
            $newQuantity = $asset->quantity - $quantity;
            $this->query->table('assets')->where('id', $id)->update([
                'quantity' => $newQuantity, 'status' => $newQuantity === 0 ? 'DISPOSED' : 'ACTIVE', 'updated_at' => now(),
            ]);
            $this->movement($actor, $id, 'DISPOSED', $quantity, $asset->location_id, null, $data['reason']);
            $this->audit($actor, 'asset.disposed', 'assets', $id, ['quantity' => $newQuantity, 'method' => $data['method'], 'status' => $newQuantity === 0 ? 'DISPOSED' : 'ACTIVE']);
            $this->audit($actor, 'asset.disposal_recorded', 'asset_disposals', $disposalId, ['asset_id' => $id, 'quantity' => $quantity, 'method' => $data['method']]);
        });
    }
}

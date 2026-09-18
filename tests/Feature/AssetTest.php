<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Asset\AssetManager;
use App\Modules\Finance\FinanceManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $tenant;

    private User $admin;

    private User $petengen;

    private User $kelian;

    private AssetManager $assets;

    private string $host = 'http://financeqa.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Asset tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fixture = FinanceFixture::create();
        $this->tenant = $this->fixture['tenant'];
        $this->admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $this->petengen = User::findOrFail($this->fixture['users']['PETENGEN']);
        $this->kelian = User::findOrFail($this->fixture['users']['KELIAN']);
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'ASSET')->update(['status' => 'ACTIVE']);
        $this->context();
        $this->assets = app(AssetManager::class);
    }

    private function context(?int $tenant = null): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant ?? $this->tenant));
    }

    private function category(string $name = 'Perlengkapan upacara'): int
    {
        return $this->assets->category($this->petengen, ['name' => $name]);
    }

    private function location(string $name = 'Balai Banjar'): int
    {
        return $this->assets->location($this->petengen, ['name' => $name]);
    }

    private function individual(array $overrides = []): int
    {
        return $this->assets->create($this->petengen, $overrides + [
            'category_id' => $this->category(), 'location_id' => $this->location(), 'kind' => 'INDIVIDUAL',
            'code' => 'AST-'.Str::random(6), 'name' => 'Sound system',
        ]);
    }

    private function quantity(int $qty = 20, array $overrides = []): int
    {
        return $this->assets->create($this->petengen, $overrides + [
            'category_id' => $this->category('Perabotan'), 'location_id' => $this->location('Gudang'), 'kind' => 'QUANTITY',
            'quantity' => $qty, 'code' => 'AST-'.Str::random(6), 'name' => 'Kursi lipat',
        ]);
    }

    private function row(string $table, int $id): object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    private function rejected(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Action unexpectedly accepted');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());
        }
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Validation unexpectedly passed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function tenantLogin(User $user): self
    {
        return $this->actingAs($user, 'web')->withSession(['auth_host' => 'financeqa.localhost', 'auth_context' => (string) $this->tenant]);
    }

    public function test_categories_and_locations_toggle_and_require_permission(): void
    {
        $category = $this->category();
        $this->invalid(fn () => $this->assets->category($this->petengen, ['name' => 'Perlengkapan upacara']), 'name');
        $this->rejected(fn () => $this->assets->category($this->kelian, ['name' => 'Lain']), 403);
        $this->assertTrue((bool) $this->row('asset_categories', $category)->is_active);
        $this->assets->toggleCategory($this->admin, $category);
        $this->assertFalse((bool) $this->row('asset_categories', $category)->is_active);
        $location = $this->location();
        $this->invalid(fn () => $this->assets->location($this->petengen, ['name' => 'Balai Banjar']), 'name');
        $this->assets->toggleLocation($this->admin, $location);
        $this->assertFalse((bool) $this->row('asset_locations', $location)->is_active);
    }

    public function test_individual_forces_single_quantity_and_quantity_kind_keeps_requested_amount(): void
    {
        $individualId = $this->individual();
        $individual = $this->row('assets', $individualId);
        $this->assertSame(1, $individual->quantity);
        $this->assertSame(1, $individual->quantity_available);
        $this->assertSame('BAIK', $individual->condition);
        $this->assertSame(1, DB::table('asset_movements')->where('asset_id', $individualId)->where('type', 'ACQUIRED')->count());

        $quantityId = $this->quantity(20);
        $batch = $this->row('assets', $quantityId);
        $this->assertSame(20, $batch->quantity);
        $this->assertSame(20, $batch->quantity_available);

        $code = $this->row('assets', $individualId)->code;
        $this->invalid(fn () => $this->assets->create($this->petengen, [
            'category_id' => $this->category('Lain'), 'location_id' => $this->location('Lain'), 'kind' => 'INDIVIDUAL', 'code' => $code, 'name' => 'Duplikat',
        ]), 'code');

        $inactiveCategory = $this->category('Nonaktif');
        $this->assets->toggleCategory($this->admin, $inactiveCategory);
        $this->invalid(fn () => $this->assets->create($this->petengen, [
            'category_id' => $inactiveCategory, 'location_id' => $this->location('Aktif'), 'kind' => 'INDIVIDUAL', 'code' => 'AST-X', 'name' => 'Barang',
        ]), 'asset');
    }

    public function test_acquisition_cash_transaction_link_is_optional_and_validated(): void
    {
        $finance = app(FinanceManager::class);
        $finance->cash($this->petengen, [
            'cash_account_id' => $this->fixture['account'], 'transaction_date' => now('Asia/Makassar')->toDateString(),
            'reference' => 'SETOR-001', 'direction' => 'IN', 'amount' => '2000000', 'reason' => 'Setoran kas awal', 'submission_key' => (string) Str::uuid(),
        ]);
        $cashId = $finance->cash($this->petengen, [
            'cash_account_id' => $this->fixture['account'], 'transaction_date' => now('Asia/Makassar')->toDateString(),
            'reference' => 'BELI-001', 'direction' => 'OUT', 'amount' => '750000', 'reason' => 'Beli sound system baru', 'submission_key' => (string) Str::uuid(),
        ]);
        $id = $this->individual(['acquisition_cash_transaction_id' => $cashId, 'acquisition_cost' => '750000', 'acquisition_date' => now('Asia/Makassar')->toDateString()]);
        $asset = $this->row('assets', $id);
        $this->assertSame($cashId, $asset->acquisition_cash_transaction_id);
        $this->assertSame(750000, $asset->acquisition_cost);
    }

    public function test_update_edits_fields_but_never_location_or_kind(): void
    {
        $id = $this->individual();
        $newCategory = $this->category('Lain');
        $this->assets->update($this->petengen, $id, ['category_id' => $newCategory, 'name' => 'Sound system baru', 'notes' => 'Diperbarui']);
        $asset = $this->row('assets', $id);
        $this->assertSame($newCategory, $asset->category_id);
        $this->assertSame('Sound system baru', $asset->name);
        $this->rejected(fn () => $this->assets->update($this->kelian, $id, ['category_id' => $newCategory, 'name' => 'Tidak diizinkan']), 403);
    }

    public function test_transfer_moves_available_stock_and_rejects_same_or_loaned_out(): void
    {
        $id = $this->quantity(10);
        $asset = $this->row('assets', $id);
        $other = $this->location('Lumbung');
        $this->invalid(fn () => $this->assets->transfer($this->petengen, $id, ['location_id' => $asset->location_id]), 'location_id');
        $this->assets->transfer($this->petengen, $id, ['location_id' => $other, 'note' => 'Dipindah untuk acara']);
        $this->assertSame($other, $this->row('assets', $id)->location_id);
        $this->assertSame(1, DB::table('asset_movements')->where('asset_id', $id)->where('type', 'TRANSFER')->count());

        $loan = $this->assets->loan($this->petengen, ['borrower_name' => 'Sekaa Truna', 'purpose' => 'Acara desa', 'loan_date' => now('Asia/Makassar')->toDateString()]);
        $this->assets->loanItem($this->petengen, $loan, ['asset_id' => $id, 'quantity' => 10, 'condition_out' => 'BAIK']);
        $this->invalid(fn () => $this->assets->transfer($this->petengen, $id, ['location_id' => $this->location('Baru')]), 'asset');
    }

    public function test_inspect_updates_condition_and_logs_history(): void
    {
        $id = $this->individual();
        $this->assets->inspect($this->petengen, $id, ['condition' => 'PERLU_PERAWATAN', 'note' => 'Suara mendengung']);
        $this->assertSame('PERLU_PERAWATAN', $this->row('assets', $id)->condition);
        $this->assertSame(1, DB::table('asset_inspections')->where('asset_id', $id)->count());
        $this->assertSame(1, DB::table('asset_movements')->where('asset_id', $id)->where('type', 'CONDITION_CHANGE')->count());
    }

    public function test_loan_requires_a_borrower_and_tracks_availability(): void
    {
        $this->invalid(fn () => $this->assets->loan($this->petengen, ['purpose' => 'Tanpa peminjam', 'loan_date' => now('Asia/Makassar')->toDateString()]), 'borrower_name');
        $krama = $this->fixture['people'][0];
        $loan = $this->assets->loan($this->petengen, ['borrower_krama_id' => $krama, 'purpose' => 'Ngayah', 'loan_date' => now('Asia/Makassar')->toDateString()]);
        $this->assertSame($krama, $this->row('asset_loans', $loan)->borrower_krama_id);

        $id = $this->quantity(5);
        $this->invalid(fn () => $this->assets->loanItem($this->petengen, $loan, ['asset_id' => $id, 'quantity' => 6, 'condition_out' => 'BAIK']), 'quantity');
        $this->assets->loanItem($this->petengen, $loan, ['asset_id' => $id, 'quantity' => 5, 'condition_out' => 'BAIK']);
        $asset = $this->row('assets', $id);
        $this->assertSame(5, $asset->quantity_on_loan);
        $this->assertSame(0, $asset->quantity_available);
        $this->invalid(fn () => $this->assets->loanItem($this->petengen, $loan, ['asset_id' => $id, 'quantity' => 1, 'condition_out' => 'BAIK']), 'asset_id');
    }

    public function test_return_updates_status_and_condition_only_for_individual_kind(): void
    {
        $krama = $this->fixture['people'][0];
        $loan = $this->assets->loan($this->petengen, ['borrower_krama_id' => $krama, 'purpose' => 'Ngayah', 'loan_date' => now('Asia/Makassar')->toDateString()]);
        $individualId = $this->individual();
        $this->assets->loanItem($this->petengen, $loan, ['asset_id' => $individualId, 'quantity' => 1, 'condition_out' => 'BAIK']);
        $individualItem = DB::table('asset_loan_items')->where('loan_id', $loan)->where('asset_id', $individualId)->value('id');

        $quantityId = $this->quantity(5);
        $this->assets->loanItem($this->petengen, $loan, ['asset_id' => $quantityId, 'quantity' => 5, 'condition_out' => 'BAIK']);
        $quantityItem = DB::table('asset_loan_items')->where('loan_id', $loan)->where('asset_id', $quantityId)->value('id');

        $this->invalid(fn () => $this->assets->returnItem($this->petengen, $loan, $quantityItem, ['quantity' => 6, 'condition_in' => 'BAIK']), 'quantity');
        $this->assets->returnItem($this->petengen, $loan, $quantityItem, ['quantity' => 3, 'condition_in' => 'RUSAK_RINGAN']);
        $this->assertSame('PARTIALLY_RETURNED', $this->row('asset_loans', $loan)->status);
        $this->assertSame(2, $this->row('assets', $quantityId)->quantity_on_loan);
        $this->assertSame('BAIK', $this->row('assets', $quantityId)->condition, 'Quantity assets keep their headline condition on partial return.');
        $this->assets->returnItem($this->petengen, $loan, $quantityItem, ['quantity' => 2, 'condition_in' => 'BAIK']);

        $this->assets->returnItem($this->petengen, $loan, $individualItem, ['quantity' => 1, 'condition_in' => 'RUSAK_BERAT']);
        $this->assertSame('RETURNED', $this->row('asset_loans', $loan)->status);
        $this->assertSame(0, $this->row('assets', $individualId)->quantity_on_loan);
        $this->assertSame('RUSAK_BERAT', $this->row('assets', $individualId)->condition, 'Individual asset condition follows the return.');
        $this->assertSame(2, DB::table('asset_inspections')->where('asset_id', $quantityId)->count());
    }

    public function test_maintenance_schedule_and_complete(): void
    {
        $id = $this->individual();
        $maintenanceId = $this->assets->scheduleMaintenance($this->petengen, $id, ['description' => 'Servis rutin', 'scheduled_at' => now('Asia/Makassar')->addDays(3)->toDateString()]);
        $this->assertSame('SCHEDULED', $this->row('asset_maintenance', $maintenanceId)->status);
        $this->assets->completeMaintenance($this->petengen, $maintenanceId, ['cost' => '150000', 'note' => 'Selesai']);
        $maintenance = $this->row('asset_maintenance', $maintenanceId);
        $this->assertSame('DONE', $maintenance->status);
        $this->assertSame(150000, $maintenance->cost);
        $this->rejected(fn () => $this->assets->completeMaintenance($this->petengen, $maintenanceId, []), 409);
    }

    public function test_stock_opname_snapshots_available_and_adjusts_on_completion(): void
    {
        $id = $this->quantity(20);
        $location = $this->row('assets', $id)->location_id;
        $loan = $this->assets->loan($this->petengen, ['borrower_name' => 'Sekaa Truna', 'purpose' => 'Acara', 'loan_date' => now('Asia/Makassar')->toDateString()]);
        $this->assets->loanItem($this->petengen, $loan, ['asset_id' => $id, 'quantity' => 4, 'condition_out' => 'BAIK']);

        $opname = $this->assets->openOpname($this->petengen, ['location_id' => $location]);
        $item = DB::table('stock_opname_items')->where('opname_id', $opname)->where('asset_id', $id)->first();
        $this->assertSame(16, $item->expected_quantity, 'Expected count excludes stock currently on loan.');

        $this->invalid(fn () => $this->assets->completeOpname($this->petengen, $opname), 'asset');
        $this->assets->countItem($this->petengen, $opname, $item->id, ['counted_quantity' => 14, 'note' => '2 hilang']);
        $result = $this->assets->completeOpname($this->petengen, $opname);
        $this->assertSame(1, $result['adjusted']);
        $asset = $this->row('assets', $id);
        $this->assertSame(18, $asset->quantity, 'Total shrinks by the counted shortfall (20 - 2).');
        $this->assertSame(4, $asset->quantity_on_loan);
        $this->assertSame(1, DB::table('asset_movements')->where('asset_id', $id)->where('type', 'ADJUSTMENT')->count());
        $this->assertSame('COMPLETED', $this->row('stock_opnames', $opname)->status);
    }

    public function test_disposal_requires_dispose_permission_and_respects_loaned_stock(): void
    {
        $id = $this->quantity(10);
        $loan = $this->assets->loan($this->petengen, ['borrower_name' => 'Sekaa Truna', 'purpose' => 'Acara', 'loan_date' => now('Asia/Makassar')->toDateString()]);
        $this->assets->loanItem($this->petengen, $loan, ['asset_id' => $id, 'quantity' => 3, 'condition_out' => 'BAIK']);

        // quantity_available is 10 - 3 on loan = 7.
        $this->rejected(fn () => $this->assets->dispose($this->petengen, $id, ['quantity' => 1, 'method' => 'DAMAGED_WRITE_OFF', 'reason' => 'Rusak berat']), 403);
        $this->invalid(fn () => $this->assets->dispose($this->admin, $id, ['quantity' => 8, 'method' => 'DAMAGED_WRITE_OFF', 'reason' => 'Rusak berat']), 'quantity');
        $this->assets->dispose($this->admin, $id, ['quantity' => 7, 'method' => 'DAMAGED_WRITE_OFF', 'reason' => 'Rusak berat total']);
        $asset = $this->row('assets', $id);
        $this->assertSame(3, $asset->quantity, 'Total drops from 10 to 3 (the 3 still on loan); loaned stock is untouched.');
        $this->assertSame(3, $asset->quantity_on_loan);
        $this->assertSame(0, $asset->quantity_available);
        $this->assertSame('ACTIVE', $asset->status, 'Cannot flip to DISPOSED while stock is still on loan.');
    }

    public function test_disposal_reaches_zero_and_locks_further_writes(): void
    {
        $id = $this->individual();
        $this->assets->dispose($this->admin, $id, ['quantity' => 1, 'method' => 'SOLD', 'reason' => 'Dijual ke Banjar lain']);
        $asset = $this->row('assets', $id);
        $this->assertSame(0, $asset->quantity);
        $this->assertSame('DISPOSED', $asset->status);
        $this->rejected(fn () => $this->assets->transfer($this->admin, $id, ['location_id' => $this->location('Lain')]), 409);
        $this->rejected(fn () => $this->assets->inspect($this->admin, $id, ['condition' => 'BAIK']), 409);
        $this->rejected(fn () => $this->assets->dispose($this->admin, $id, ['quantity' => 1, 'method' => 'SOLD', 'reason' => 'Dua kali']), 409);
    }

    public function test_database_check_constraint_rejects_individual_with_multiple_quantity(): void
    {
        $this->expectException(QueryException::class);
        DB::table('assets')->insert([
            'banjar_id' => $this->tenant, 'category_id' => $this->category(), 'location_id' => $this->location(),
            'kind' => 'INDIVIDUAL', 'code' => 'BAD-1', 'name' => 'Rusak aturan', 'quantity' => 2,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_module_gate_and_tenant_isolation(): void
    {
        $id = $this->individual();
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'ASSET')->update(['status' => 'INACTIVE']);
        $this->rejected(fn () => $this->assets->category($this->petengen, ['name' => 'Baru']), 403);
        $this->tenantLogin($this->admin)->get($this->host.'/admin/assets')->assertForbidden();
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'ASSET')->update(['status' => 'ACTIVE']);

        $super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        $other = app(TenantManager::class)->create($super, ['name' => 'Banjar Lain', 'slug' => 'asset-other', 'admin_name' => 'Lain', 'admin_email' => 'asset-other@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        app(TenantManager::class)->module($super, $other, 'ASSET', ['status' => 'ACTIVE']);
        $otherAdmin = User::where('email', 'asset-other@example.test')->firstOrFail();
        $this->context($other);
        $this->rejected(fn () => $this->assets->update($otherAdmin, $id, ['category_id' => 1, 'name' => 'Coba akses']), 404);
        $this->context();

        $this->tenantLogin($this->admin)->get($this->host.'/admin/assets/'.$id)->assertOk()->assertSee('Sound system');
    }

    public function test_http_asset_and_loan_flow(): void
    {
        $category = $this->category();
        $location = $this->location();
        $this->tenantLogin($this->petengen)->post($this->host.'/admin/assets', [
            'category_id' => $category, 'location_id' => $location, 'kind' => 'QUANTITY', 'quantity' => 12, 'code' => 'AST-HTTP-1', 'name' => 'Tikar',
        ])->assertRedirect();
        $id = DB::table('assets')->where('code', 'AST-HTTP-1')->value('id');
        $this->assertNotNull($id);

        $this->tenantLogin($this->petengen)->post($this->host.'/admin/assets/loans', [
            'borrower_name' => 'Sekaa Truna', 'purpose' => 'Acara adat', 'loan_date' => now('Asia/Makassar')->toDateString(),
        ])->assertRedirect();
        $loanId = DB::table('asset_loans')->where('purpose', 'Acara adat')->value('id');
        $this->tenantLogin($this->petengen)->post($this->host.'/admin/assets/loans/'.$loanId.'/items', ['asset_id' => $id, 'quantity' => 4, 'condition_out' => 'BAIK'])->assertRedirect();
        $this->assertSame(4, $this->row('assets', $id)->quantity_on_loan);

        $itemId = DB::table('asset_loan_items')->where('loan_id', $loanId)->value('id');
        $this->tenantLogin($this->petengen)->post($this->host.'/admin/assets/loans/'.$loanId.'/items/'.$itemId.'/return', ['quantity' => 4, 'condition_in' => 'BAIK'])->assertRedirect();
        $this->assertSame(0, $this->row('assets', $id)->quantity_on_loan);
        $this->assertSame('RETURNED', $this->row('asset_loans', $loanId)->status);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Finance\FinanceReportManager;
use App\Modules\Krama\KramaManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    private User $treasurer;

    private User $kesinoman;

    private int $tenant;

    private int $cashAccount;

    private int $bankAccount;

    private int $household;

    private FinanceManager $finance;

    private FinanceReportManager $reports;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Refusing to refresh anything except banjarv2_testing on MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        $this->tenant = app(TenantManager::class)->create($super, ['name' => 'Banjar Laporan', 'slug' => 'laporan-qa', 'admin_name' => 'Admin QA', 'admin_email' => 'admin-laporan@example.test', 'admin_password' => 'Test-Password-12345', 'admin_password_confirmation' => 'Test-Password-12345']);
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->whereIn('code', ['KRAMA', 'FINANCE'])->update(['status' => 'ACTIVE']);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->tenant));
        $this->treasurer = $this->role('PETENGEN');
        $this->kesinoman = $this->role('KESINOMAN');
        $this->finance = app(FinanceManager::class);
        $this->reports = app(FinanceReportManager::class);
        $this->cashAccount = $this->finance->account($this->treasurer, ['name' => 'Kas Tunai', 'type' => 'CASH']);
        $this->bankAccount = $this->finance->account($this->treasurer, ['name' => 'Bank ABC', 'type' => 'BANK']);
        $admin = User::where('email', 'admin-laporan@example.test')->firstOrFail();
        $this->household = app(KramaManager::class)->household($admin, null, ['code' => 'KK-LAPORAN', 'status' => 'ACTIVE']);
    }

    private function context(): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($this->tenant));
    }

    private function role(string $code): User
    {
        $user = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'BANJAR']);

        return $user;
    }

    private function cash(int $account, string $direction, int $amount, string $date, ?int $categoryId, string $reason): int
    {
        return $this->finance->cash($this->treasurer, ['cash_account_id' => $account, 'direction' => $direction, 'amount' => (string) $amount,
            'category_id' => $categoryId, 'transaction_date' => $date, 'reference' => 'BUKTI-'.Str::random(6), 'reason' => $reason, 'submission_key' => (string) Str::uuid()]);
    }

    /**
     * A fully self-consistent operational fixture (round numbers I control end to end), distinct from the
     * Master Prompt §26.8 reference numbers — those only prove the REFERENCE DOCUMENT's own arithmetic is
     * internally consistent (its "saldo pindahan" already blends in piutang, per §26.6) and are not a full
     * journal we can reconstruct; this fixture proves FinanceReportManager::compute() itself is correct.
     */
    private function buildPeriodFixture(): array
    {
        $incomeRutin = $this->finance->category($this->treasurer, ['name' => 'Pendapatan Rutin', 'kind' => 'IN', 'always_show' => true]);
        $incomeLain = $this->finance->category($this->treasurer, ['name' => 'Pendapatan Lain-lain', 'kind' => 'IN']);
        $incomeSewa = $this->finance->category($this->treasurer, ['name' => 'Pendapatan Sewa', 'kind' => 'IN', 'always_show' => true]); // stays NIHIL
        $expenseOps = $this->finance->category($this->treasurer, ['name' => 'Biaya Operasional', 'kind' => 'OUT', 'always_show' => true]);
        $expenseUpacara = $this->finance->category($this->treasurer, ['name' => 'Biaya Upacara', 'kind' => 'OUT']);

        // Pre-period seed ("saldo awal") transactions establish the account balances behind the manual opening balance.
        $this->cash($this->cashAccount, 'IN', 300000, '2025-12-31', null, 'Saldo awal migrasi kas tunai');
        $this->cash($this->bankAccount, 'IN', 200000, '2025-12-31', null, 'Saldo awal migrasi rekening bank');

        // In-period transactions (2026-01-01 .. 2026-01-31).
        $this->cash($this->cashAccount, 'IN', 100000, '2026-01-05', $incomeRutin, 'Iuran rutin minggu 1');
        $this->cash($this->cashAccount, 'IN', 50000, '2026-01-20', $incomeRutin, 'Iuran rutin minggu 3');
        $this->cash($this->cashAccount, 'IN', 20000, '2026-01-10', $incomeLain, 'Dana punia');
        $this->cash($this->cashAccount, 'IN', 5000, '2026-01-15', null, 'Pemasukan belum dikategorikan');
        $this->cash($this->cashAccount, 'OUT', 30000, '2026-01-06', $expenseOps, 'Alat tulis kantor');
        $this->cash($this->cashAccount, 'OUT', 15000, '2026-01-25', $expenseOps, 'Konsumsi rapat');
        $this->cash($this->cashAccount, 'OUT', 25000, '2026-01-12', $expenseUpacara, 'Banten piodalan');

        $report = $this->reports->create($this->treasurer, ['period_from' => '2026-01-01', 'period_to' => '2026-01-31',
            'sangkep_title' => 'SANGKEP UJI LAPORAN', 'sangkep_date' => '2026-01-31',
            'opening_balance' => '500000', 'opening_balance_note' => 'Saldo awal migrasi buku kas manual sebelum sistem digunakan.']);

        return compact('incomeRutin', 'incomeLain', 'incomeSewa', 'expenseOps', 'expenseUpacara', 'report');
    }

    public function test_reference_document_arithmetic_is_internally_consistent(): void
    {
        // Master Prompt §26.8 — proves the REFERENCE PDF's own numbers add up; not app behavior.
        $this->assertSame(273613046, 16259790 + 225516656 + 31836600);
        $this->assertSame(851260074, 655284817 + 273613046 - 77637789);
        $this->assertSame(851260074, 216648196 + 616802657 + 11209221 + 6600000);
    }

    public function test_report_computes_category_subtotals_totals_and_nihil_categories(): void
    {
        $fixture = $this->buildPeriodFixture();
        $data = $this->reports->compute(DB::table('finance_reports')->find($fixture['report']));
        $this->assertSame(175000, $data['total_pemasukan']);
        $this->assertSame(70000, $data['total_pengeluaran']);
        $this->assertSame(605000, $data['saldo_akhir']); // 500000 + 175000 - 70000
        $rutin = collect($data['pendapatan'])->firstWhere('category.id', $fixture['incomeRutin']);
        $this->assertSame(150000, $rutin['subtotal']);
        $sewa = collect($data['pendapatan'])->firstWhere('category.id', $fixture['incomeSewa']);
        $this->assertSame([], $sewa['lines']); // NIHIL category with no transactions still appears
        $uncategorized = collect($data['pendapatan'])->firstWhere('category.id', null);
        $this->assertSame(5000, $uncategorized['subtotal']);
        $this->assertSame('Belum dikategorikan', $uncategorized['category']['name']);
    }

    public function test_posisi_dana_reconciles_accounts_with_piutang_as_of_cutoff(): void
    {
        $fixture = $this->buildPeriodFixture();
        // One bill still unpaid as of the cutoff: real piutang.
        $type = $this->finance->billingType($this->kesinoman, ['name' => 'Iuran Piutang', 'kind' => 'IURAN', 'default_amount' => '40000']);
        $event = $this->finance->event($this->kesinoman, ['billing_type_id' => $type, 'title' => 'Iuran Januari', 'reference' => (string) Str::uuid()]);
        $this->finance->issue($this->kesinoman, $event);
        DB::table('billing_events')->where('id', $event)->update(['issued_at' => '2026-01-20 00:00:00']);

        // A second bill issued before cutoff but SETTLED AFTER the cutoff must still count as piutang as of the cutoff.
        $type2 = $this->finance->billingType($this->kesinoman, ['name' => 'Iuran Piutang Lambat', 'kind' => 'IURAN', 'default_amount' => '15000']);
        $event2 = $this->finance->event($this->kesinoman, ['billing_type_id' => $type2, 'title' => 'Iuran Piutang Lambat', 'reference' => (string) Str::uuid()]);
        $this->finance->issue($this->kesinoman, $event2);
        DB::table('billing_events')->where('id', $event2)->update(['issued_at' => '2026-01-10 00:00:00']);
        $bill2 = DB::table('bills')->where('billing_event_id', $event2)->value('id');
        $this->finance->settle($this->treasurer, $bill2, ['cash_account_id' => $this->cashAccount, 'submission_key' => (string) Str::uuid(), 'transaction_date' => '2026-02-15', 'reference' => 'BUKTI-LAMBAT']);

        $data = $this->reports->compute(DB::table('finance_reports')->find($fixture['report']));
        $this->assertSame(55000, $data['piutang']['total']); // 40000 + 15000, both still open as of 2026-01-31
        $this->assertSame(2, $data['piutang']['count']);
        $accountsById = collect($data['accounts'])->keyBy('id');
        $this->assertSame(405000, $accountsById[$this->cashAccount]['balance']); // 300000 + 175000 - 70000
        $this->assertSame(200000, $accountsById[$this->bankAccount]['balance']);
        $this->assertSame(605000 + 55000, $data['posisi_dana_total']);
    }

    public function test_finalize_freezes_snapshot_against_later_transactions_and_revise_creates_new_version(): void
    {
        $fixture = $this->buildPeriodFixture();
        $this->reports->finalize($this->treasurer, $fixture['report'], ['signer_treasurer_name' => 'Made Petengen', 'signer_kelian_name' => 'Wayan Kelian']);
        $this->context(); // sync queue clears tenant context after the dispatched PDF job runs
        $before = $this->reports->compute($this->reports->row($fixture['report']));
        $this->cash($this->cashAccount, 'IN', 999000, '2026-01-28', $fixture['incomeRutin'], 'Transaksi baru setelah finalisasi');
        $after = $this->reports->compute($this->reports->row($fixture['report']));
        $this->assertSame($before, $after);
        $this->assertSame(175000, $after['total_pemasukan']);

        $revised = $this->reports->revise($this->treasurer, $fixture['report'], ['reason' => 'Koreksi kategori setelah pemeriksaan ulang']);
        $this->assertNotSame($fixture['report'], $revised);
        $this->assertDatabaseHas('finance_reports', ['id' => $fixture['report'], 'superseded_by_id' => $revised]);
        $this->assertDatabaseHas('finance_reports', ['id' => $revised, 'status' => 'DRAFT', 'version' => 2]);
    }

    public function test_chained_report_requires_contiguous_period_and_inherits_opening_balance(): void
    {
        $fixture = $this->buildPeriodFixture();
        $this->reports->finalize($this->treasurer, $fixture['report'], ['signer_treasurer_name' => 'Made Petengen', 'signer_kelian_name' => 'Wayan Kelian']);
        $this->context(); // sync queue clears tenant context after the dispatched PDF job runs
        try {
            $this->reports->create($this->treasurer, ['period_from' => '2026-02-05', 'period_to' => '2026-02-28', 'sangkep_title' => 'Sangkep Februari', 'previous_report_id' => $fixture['report']]);
            $this->fail('Expected a contiguous-period validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period_from', $e->errors());
        }
        $next = $this->reports->create($this->treasurer, ['period_from' => '2026-02-01', 'period_to' => '2026-02-28', 'sangkep_title' => 'Sangkep Februari', 'previous_report_id' => $fixture['report']]);
        $this->assertDatabaseHas('finance_reports', ['id' => $next, 'opening_balance_source' => 'PREVIOUS_REPORT', 'opening_balance' => 605000]);
    }
}

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
        // Iuran hanya dikenakan ke kepala keluarga.
        $head = app(KramaManager::class)->person($admin, null, ['household_id' => $this->household, 'name' => 'Kepala KK Laporan', 'status' => 'ACTIVE']);
        DB::table('households')->where('id', $this->household)->update(['head_krama_id' => $head]);
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

        // Laporan pertama (Desember) mencakup transaksi saldo awal di buku kas; laporan Januari melanjutkan saldonya.
        $december = $this->reports->create($this->treasurer, ['sangkep_title' => 'SANGKEP DESEMBER', 'sangkep_date' => '2025-12-31']);
        $this->reports->finalize($this->treasurer, $december, []);
        $this->context(); // sync queue clears tenant context after the dispatched PDF job runs
        $report = $this->reports->create($this->treasurer, ['sangkep_title' => 'SANGKEP UJI LAPORAN', 'sangkep_date' => '2026-01-31']);

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
        $event = $this->finance->event($this->kesinoman, ['billing_type_id' => $type, 'title' => 'Iuran Januari', 'reference' => (string) Str::uuid(), 'event_date' => '2026-01-20']);
        $this->finance->issue($this->kesinoman, $event);
        DB::table('billing_events')->where('id', $event)->update(['issued_at' => '2026-01-20 00:00:00']);

        // A second bill issued before cutoff but SETTLED AFTER the cutoff must still count as piutang as of the cutoff.
        $type2 = $this->finance->billingType($this->kesinoman, ['name' => 'Iuran Piutang Lambat', 'kind' => 'IURAN', 'default_amount' => '15000']);
        $event2 = $this->finance->event($this->kesinoman, ['billing_type_id' => $type2, 'title' => 'Iuran Piutang Lambat', 'reference' => (string) Str::uuid(), 'event_date' => '2026-01-10']);
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
        // Nama penanda tangan diisi sistem dari akun pengurus.
        $this->reports->finalize($this->treasurer, $fixture['report'], []);
        $this->context(); // sync queue clears tenant context after the dispatched PDF job runs
        $this->assertDatabaseHas('finance_reports', ['id' => $fixture['report'], 'signer_treasurer_name' => $this->treasurer->name]);
        try {
            $this->reports->create($this->treasurer, ['sangkep_title' => 'Sangkep mundur', 'sangkep_date' => '2026-01-15']);
            $this->fail('Tanggal sangkep sebelum akhir laporan sebelumnya harus ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sangkep_date', $e->errors());
        }
        // Periode mulai, akhir, dan saldo pindahan ditentukan sistem; isian periode/saldo dari luar diabaikan.
        $next = $this->reports->create($this->treasurer, ['sangkep_title' => 'Sangkep Februari', 'sangkep_date' => '2026-02-28', 'period_from' => '2026-02-10', 'opening_balance' => '1']);
        $this->assertDatabaseHas('finance_reports', ['id' => $next, 'period_from' => '2026-02-01', 'period_to' => '2026-02-28', 'sangkep_date' => '2026-02-28', 'opening_balance_source' => 'PREVIOUS_REPORT', 'opening_balance' => 605000]);
        try {
            $this->reports->create($this->treasurer, ['sangkep_title' => 'Sangkep Maret', 'sangkep_date' => '2026-03-31']);
            $this->fail('Draf yang belum final harus diselesaikan dulu.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Masih ada draf', $e->errors()['sangkep_title'][0]);
        }
    }

    // Petengen hanya mengisi nama & tanggal sangkep; nama penanda tangan sudah terisi saat finalisasi.
    public function test_report_forms_ask_only_for_sangkep_name_and_date(): void
    {
        $kelian = $this->role('KELIAN');
        DB::table('users')->where('id', $kelian->id)->update(['name' => 'Wayan Kelian']);
        $host = 'http://laporan-qa.localhost';
        $session = fn () => $this->actingAs($this->treasurer)->withSession(['auth_host' => 'laporan-qa.localhost', 'auth_context' => (string) $this->tenant]);
        $html = $session()->get($host.'/admin/finance/reports/create')->assertOk()->getContent();
        $this->assertStringContainsString('name="sangkep_title"', $html);
        $this->assertStringContainsString('name="sangkep_date"', $html);
        foreach (['period_from', 'period_to', 'opening_balance', 'opening_balance_note'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html);
        }
        $session()->post($host.'/admin/finance/reports', ['sangkep_title' => 'Sangkep Purnama', 'sangkep_date' => '2026-03-31'])->assertSessionHasNoErrors();
        $id = DB::table('finance_reports')->where('sangkep_title', 'Sangkep Purnama')->value('id');
        $session()->get($host.'/admin/finance/reports/'.$id)->assertOk()->assertSee('Ditandatangani')->assertSee($this->treasurer->name)->assertSee('Wayan Kelian')
            ->assertSee('SALDO PINDAHAN SANGKEP TGL. 30 Maret 2026');
        $session()->post($host.'/admin/finance/reports/'.$id.'/finalize', [])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('finance_reports', ['id' => $id, 'status' => 'FINALIZED', 'signer_treasurer_name' => $this->treasurer->name, 'signer_kelian_name' => 'Wayan Kelian']);
    }

    // Sebelum final, draf boleh diperbaiki (nama/tanggal sangkep) atau dibatalkan; buku kas tidak berubah.
    public function test_draft_can_be_edited_or_discarded_before_finalization(): void
    {
        $this->cash($this->cashAccount, 'IN', 400000, '2026-03-01', null, 'Saldo awal');
        $this->cash($this->cashAccount, 'IN', 50000, '2026-03-20', null, 'Iuran');
        $id = $this->reports->create($this->treasurer, ['sangkep_title' => 'Sangkep slah ketik', 'sangkep_date' => '2026-03-10']);
        $this->reports->updateDraft($this->treasurer, $id, ['sangkep_title' => 'Sangkep Purnama', 'sangkep_date' => '2026-03-31']);
        $this->assertDatabaseHas('finance_reports', ['id' => $id, 'sangkep_title' => 'Sangkep Purnama', 'sangkep_date' => '2026-03-31', 'period_from' => '2026-03-02', 'period_to' => '2026-03-31', 'opening_balance' => 400000]);
        $this->assertSame(450000, $this->reports->compute($this->reports->row($id))['saldo_akhir']);

        $title = $this->reports->discard($this->treasurer, $id);
        $this->assertSame('Sangkep Purnama', $title);
        $this->assertDatabaseMissing('finance_reports', ['id' => $id]);
        $this->assertSame(2, DB::table('cash_transactions')->where('banjar_id', $this->tenant)->count(), 'Buku kas tidak ikut terhapus.');
        $this->assertDatabaseHas('audit_logs', ['banjar_id' => $this->tenant, 'action' => 'finance.report_discarded', 'entity_id' => $id]);

        // Setelah dibatalkan, laporan baru bisa dibuat; laporan final tidak bisa diubah atau dibatalkan.
        $final = $this->reports->create($this->treasurer, ['sangkep_title' => 'Sangkep Maret', 'sangkep_date' => '2026-03-31']);
        $this->reports->finalize($this->treasurer, $final, []);
        $this->context();
        foreach ([fn () => $this->reports->updateDraft($this->treasurer, $final, ['sangkep_title' => 'X', 'sangkep_date' => '2026-03-31']), fn () => $this->reports->discard($this->treasurer, $final)] as $attempt) {
            try {
                $attempt();
                $this->fail('Laporan final tidak boleh diubah atau dibatalkan.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }

        // Draf koreksi: hanya nama yang bisa diubah; dibatalkan, versi final lama berlaku lagi.
        $revision = $this->reports->revise($this->treasurer, $final, ['reason' => 'Salah nama sangkep']);
        $this->reports->updateDraft($this->treasurer, $revision, ['sangkep_title' => 'Sangkep Maret (koreksi)', 'sangkep_date' => '2026-04-30']);
        $this->assertDatabaseHas('finance_reports', ['id' => $revision, 'sangkep_title' => 'Sangkep Maret (koreksi)', 'period_to' => '2026-03-31']);
        $this->reports->discard($this->treasurer, $revision);
        $this->assertDatabaseHas('finance_reports', ['id' => $final, 'status' => 'FINALIZED', 'superseded_by_id' => null]);
        $next = $this->reports->create($this->treasurer, ['sangkep_title' => 'Sangkep April', 'sangkep_date' => '2026-04-30']);
        $this->assertDatabaseHas('finance_reports', ['id' => $next, 'previous_report_id' => $final, 'period_from' => '2026-04-01']);

        // Tombolnya tersedia di halaman draf.
        $host = 'http://laporan-qa.localhost';
        $this->actingAs($this->treasurer)->withSession(['auth_host' => 'laporan-qa.localhost', 'auth_context' => (string) $this->tenant])
            ->get($host.'/admin/finance/reports/'.$next)->assertOk()->assertSee('Batalkan draf laporan')->assertSee('Ubah nama atau tanggal sangkep');
        $this->actingAs($this->treasurer)->withSession(['auth_host' => 'laporan-qa.localhost', 'auth_context' => (string) $this->tenant])
            ->post($host.'/admin/finance/reports/'.$next.'/discard')->assertRedirect('/admin/finance/reports');
        $this->assertDatabaseMissing('finance_reports', ['id' => $next]);
    }

    // Laporan pertama: saldo awal sudah ada di buku kas, jadi tidak diisi lagi; yang tercatat pada hari pertama buku kas
    // menjadi saldo pindahan, bukan pendapatan periode (§26.4), dan tidak terhitung dua kali.
    public function test_first_report_takes_opening_balance_from_the_first_cash_book_day(): void
    {
        $this->cash($this->cashAccount, 'IN', 1000000, '2026-03-01', null, 'Saldo awal');
        $this->cash($this->cashAccount, 'OUT', 250000, '2026-03-10', null, 'Banten');
        $this->cash($this->cashAccount, 'IN', 100000, '2026-03-15', null, 'Iuran');
        $id = $this->reports->create($this->treasurer, ['sangkep_title' => 'Sangkep pertama', 'sangkep_date' => '2026-03-31']);
        $report = DB::table('finance_reports')->find($id);
        $this->assertSame(['2026-03-02', '2026-03-31', 'MANUAL', 1000000], [$report->period_from, $report->period_to, $report->opening_balance_source, (int) $report->opening_balance]);
        $data = $this->reports->compute($this->reports->row($id));
        $this->assertSame([1000000, 100000, 250000, 850000], [$data['opening_balance'], $data['total_pemasukan'], $data['total_pengeluaran'], $data['saldo_akhir']]);
    }
}

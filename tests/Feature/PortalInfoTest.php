<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceReportManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinanceFixture;
use Tests\TestCase;

// §53: informasi untuk warga — laporan kas sangkep, jadwal kegiatan, riwayat pembayaran tunai & QRIS.
class PortalInfoTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $tenant;

    private User $krama;

    private User $petengen;

    private int $household;

    private string $host = 'http://financeqa.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Portal info tests require banjarv2_testing.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fixture = FinanceFixture::create();
        $this->tenant = $this->fixture['tenant'];
        $this->petengen = User::findOrFail($this->fixture['users']['PETENGEN']);
        $this->context();
        $this->krama = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->tenant, 'user_id' => $this->krama->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'KRAMA')->value('id'), 'scope_type' => 'BANJAR']);
        DB::table('krama')->where('id', $this->fixture['people'][0])->update(['user_id' => $this->krama->id]);
        $this->household = DB::table('krama')->where('id', $this->fixture['people'][0])->value('household_id');
    }

    private function context(?int $tenant = null): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant ?? $this->tenant));
    }

    private function login(User $user)
    {
        return $this->actingAs($user, 'web')->withSession(['auth_host' => 'financeqa.localhost', 'auth_context' => (string) $this->tenant]);
    }

    private function report(string $title, string $from, string $to, bool $finalize = true): int
    {
        $this->context();
        $reports = app(FinanceReportManager::class);
        // Saldo awal Banjar dicatat di buku kas; laporan cukup diberi nama dan tanggal sangkep.
        if (! DB::table('cash_transactions')->where('banjar_id', $this->tenant)->exists()) {
            app(\App\Modules\Finance\FinanceManager::class)->cash($this->petengen, ['cash_account_id' => $this->fixture['account'], 'direction' => 'IN', 'amount' => '150000',
                'transaction_date' => $from, 'reference' => 'SALDO-AWAL', 'reason' => 'Saldo awal buku kas', 'submission_key' => (string) \Illuminate\Support\Str::uuid()]);
        }
        $id = $reports->create($this->petengen, ['sangkep_title' => $title, 'sangkep_date' => $to]);
        if ($finalize) {
            $reports->finalize($this->petengen, $id, ['signer_treasurer_name' => 'Petengen QA', 'signer_kelian_name' => 'Kelian QA']);
        }

        return $id;
    }

    private function paidBill(int $household, string $title, int $amount, string $method, ?int $tenant = null): int
    {
        $tenant ??= $this->tenant;
        $type = DB::table('billing_types')->insertGetId(['banjar_id' => $tenant, 'name' => $title, 'kind' => 'IURAN', 'default_amount' => $amount, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $event = DB::table('billing_events')->insertGetId(['banjar_id' => $tenant, 'billing_type_id' => $type, 'kind' => 'IURAN', 'amount' => $amount, 'title' => $title, 'reference' => 'REF-'.$type, 'event_date' => '2026-10-05', 'target' => 'ALL_ACTIVE', 'status' => 'ISSUED', 'requires_approval' => false, 'created_by' => $this->petengen->id, 'created_at' => now(), 'updated_at' => now(), 'issued_at' => now()]);
        $bill = DB::table('bills')->insertGetId(['banjar_id' => $tenant, 'billing_event_id' => $event, 'kk_id' => $household, 'household_code' => 'KK', 'kind' => 'IURAN', 'gross_amount' => $amount, 'status' => 'PAID', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bill_settlements')->insert(['banjar_id' => $tenant, 'bill_id' => $bill, 'method' => $method, 'amount' => $amount, 'created_by' => $this->petengen->id, 'created_at' => now(), 'updated_at' => now()]);

        return $bill;
    }

    public function test_warga_sees_only_finalized_reports(): void
    {
        $final = $this->report('Sangkep Kajeng Kliwon Oktober', '2026-10-01', '2026-10-31');
        $this->report('Sangkep November (draf)', '2026-11-01', '2026-11-30', false);

        $this->login($this->krama)->get($this->host.'/portal/laporan')->assertOk()->assertSee('Sangkep Kajeng Kliwon Oktober')->assertSee('Rp 150.000')->assertDontSee('Sangkep November (draf)');
        $this->login($this->krama)->get($this->host.'/portal/laporan/'.$final)->assertOk()->assertSee('LAPORAN KAS')->assertSee('Petengen QA')->assertSee('POSISI DANA');
        $draft = DB::table('finance_reports')->where('status', 'DRAFT')->value('id');
        $this->login($this->krama)->get($this->host.'/portal/laporan/'.$draft)->assertNotFound();
        $this->login($this->krama)->get($this->host.'/portal/beranda')->assertOk()->assertSee('LAPORAN KEUANGAN')->assertSee('Sangkep Kajeng Kliwon Oktober');
    }

    public function test_revision_keeps_last_final_version_visible_until_new_one_is_final(): void
    {
        $v1 = $this->report('Sangkep Oktober', '2026-10-01', '2026-10-31');
        $reports = app(FinanceReportManager::class);
        $this->context();
        $v2 = $reports->revise($this->petengen, $v1, ['reason' => 'Koreksi salah ketik nominal']);

        $this->login($this->krama)->get($this->host.'/portal/laporan/'.$v1)->assertOk();
        $this->login($this->krama)->get($this->host.'/portal/laporan/'.$v2)->assertNotFound();

        $this->context();
        $reports->finalize($this->petengen, $v2, ['signer_treasurer_name' => 'Petengen QA', 'signer_kelian_name' => 'Kelian QA']);
        $this->login($this->krama)->get($this->host.'/portal/laporan/'.$v1)->assertNotFound();
        $this->login($this->krama)->get($this->host.'/portal/laporan/'.$v2)->assertOk()->assertSee('VERSI 2');
    }

    public function test_payment_history_shows_cash_and_qris_for_own_household_only(): void
    {
        $this->paidBill($this->household, 'Dedosan ngayah pura', 25000, 'MANUAL');
        $this->paidBill($this->household, 'Iuran piodalan', 50000, 'GATEWAY');
        $otherKk = DB::table('krama')->where('id', $this->fixture['people'][1])->value('household_id');
        $this->paidBill($otherKk, 'Tagihan KK lain', 777000, 'MANUAL');

        $page = $this->login($this->krama)->get($this->host.'/portal/riwayat-pembayaran')->assertOk();
        $page->assertSee('Dedosan ngayah pura')->assertSee('Tunai')->assertSee('Iuran piodalan')->assertSee('QRIS')->assertSee('Rp 75.000')->assertDontSee('Tagihan KK lain')->assertDontSee('777.000');
        $this->login($this->krama)->get($this->host.'/portal/beranda')->assertOk()->assertSee('RIWAYAT PEMBAYARAN');
    }

    public function test_reversed_settlement_is_not_listed(): void
    {
        $bill = $this->paidBill($this->household, 'Pelunasan dibatalkan', 30000, 'MANUAL');
        DB::table('bill_settlements')->where('bill_id', $bill)->update(['reversed_at' => now()]);
        DB::table('bills')->where('id', $bill)->update(['status' => 'UNPAID']);

        $this->login($this->krama)->get($this->host.'/portal/riwayat-pembayaran')->assertOk()->assertDontSee('Pelunasan dibatalkan')->assertSee('1 tagihan belum lunas');
    }

    public function test_schedule_lists_published_banjar_activities(): void
    {
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'ACTIVITY')->update(['status' => 'ACTIVE']);
        $row = fn ($title, $status, $starts, $visibility = 'PUBLIC', $published = null) => ['banjar_id' => $this->tenant, 'title' => $title, 'slug' => Str::slug($title), 'status' => $status, 'published_at' => $published ?? now()->subDay(), 'starts_at' => $starts, 'location' => 'Balai Banjar', 'visibility' => $visibility, 'created_at' => now(), 'updated_at' => now()];
        DB::table('activities')->insert([
            $row('Paruman krama Kajeng', 'PUBLISHED', now()->addDays(3)),
            $row('Draf kegiatan rahasia', 'DRAFT', now()->addDays(4)),
            $row('Kegiatan bulan lalu', 'PUBLISHED', now()->subDays(20)),
            $row('Rapat prajuru tertutup', 'PUBLISHED', now()->addDays(5), 'INTERNAL'),
            $row('Pengumuman terjadwal', 'PUBLISHED', now()->addDays(6), 'PUBLIC', now()->addDays(2)),
        ]);

        $this->login($this->krama)->get($this->host.'/aktivitas')->assertOk()->assertSee('Jadwal kegiatan')->assertSee('Paruman krama Kajeng')->assertSee('Balai Banjar')
            ->assertDontSee('Draf kegiatan rahasia')->assertDontSee('Kegiatan bulan lalu')->assertDontSee('Rapat prajuru tertutup')->assertDontSee('Pengumuman terjadwal');
        $this->login($this->krama)->get($this->host.'/portal/beranda')->assertOk()->assertSee('KEGIATAN TERDEKAT')->assertSee('Paruman krama Kajeng');
    }

    public function test_internal_activity_is_shown_to_its_participants(): void
    {
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'ACTIVITY')->update(['status' => 'ACTIVE']);
        $id = DB::table('activities')->insertGetId(['banjar_id' => $this->tenant, 'title' => 'Paruman tertutup KK terpilih', 'slug' => 'paruman-tertutup', 'status' => 'PUBLISHED', 'published_at' => now()->subDay(), 'starts_at' => now()->addDays(2), 'visibility' => 'INTERNAL', 'kind' => 'PARUMAN', 'attendance_basis' => 'HOUSEHOLD', 'attendance_status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('activity_participants')->insert(['banjar_id' => $this->tenant, 'activity_id' => $id, 'basis' => 'HOUSEHOLD', 'household_id' => $this->household, 'household_code' => 'KK-QA-001', 'status' => 'BELUM_DIVERIFIKASI', 'created_at' => now(), 'updated_at' => now()]);

        $this->login($this->krama)->get($this->host.'/aktivitas')->assertOk()->assertSee('Paruman tertutup KK terpilih')->assertSee('Presensi untuk Anda');
    }

    public function test_requires_krama_role_and_finance_module_and_isolates_banjar(): void
    {
        $this->login(User::where('email', 'admin-finance@example.test')->firstOrFail())->get($this->host.'/portal/laporan')->assertForbidden();
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'FINANCE')->update(['status' => 'INACTIVE']);
        $this->login($this->krama)->get($this->host.'/portal/riwayat-pembayaran')->assertForbidden();
        DB::table('banjar_modules')->where('banjar_id', $this->tenant)->where('code', 'FINANCE')->update(['status' => 'ACTIVE']);

        $super = User::whereIn('id', DB::table('platform_role_assignments')->select('user_id'))->firstOrFail();
        $other = app(TenantManager::class)->create($super, ['name' => 'Banjar Lain', 'slug' => 'info-lain', 'admin_name' => 'Lain', 'admin_email' => 'info-lain@example.test', 'admin_password' => 'Test-Password-123456', 'admin_password_confirmation' => 'Test-Password-123456']);
        DB::table('finance_reports')->insert(['banjar_id' => $other, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'sangkep_title' => 'Laporan banjar lain', 'status' => 'FINALIZED', 'opening_balance_source' => 'MANUAL', 'opening_balance' => 0, 'opening_balance_note' => 'x', 'computed_snapshot' => '{}', 'created_by' => $super->id, 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('finance_reports')->where('banjar_id', $other)->value('id');

        $this->login($this->krama)->get($this->host.'/portal/laporan')->assertOk()->assertDontSee('Laporan banjar lain');
        $this->login($this->krama)->get($this->host.'/portal/laporan/'.$foreign)->assertNotFound();
    }
}

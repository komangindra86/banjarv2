<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Krama\KramaManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private User $treasurer;

    private User $kesinoman;

    private User $kelian;

    private int $a;

    private int $b;

    private int $household;

    private int $second;

    private int $person;

    private int $sibling;

    private int $account;

    private FinanceManager $finance;

    private string $host = 'http://alpha.localhost';

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
        config(['platform.host' => 'admin.banjar.localhost', 'platform.tenant_base_domain' => 'localhost']);
        $this->seed(RoleSeeder::class);
        $this->super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $this->super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        foreach (['a' => 'alpha', 'b' => 'beta'] as $key => $slug) {
            $this->$key = app(TenantManager::class)->create($this->super, ['name' => 'Banjar '.$slug, 'slug' => $slug, 'admin_name' => 'Admin '.$slug, 'admin_email' => $slug.'@example.test', 'admin_password' => 'Test-Password-12345', 'admin_password_confirmation' => 'Test-Password-12345']);
            DB::table('banjar_modules')->where('banjar_id', $this->$key)->whereIn('code', ['KRAMA', 'FINANCE'])->update(['status' => 'ACTIVE']);
        }
        $this->admin = User::where('email', 'alpha@example.test')->firstOrFail();
        $this->context();
        $this->treasurer = $this->role('PETENGEN');
        $this->kesinoman = $this->role('KESINOMAN');
        $this->kelian = $this->role('KELIAN');
        $krama = app(KramaManager::class);
        $this->household = $krama->household($this->admin, null, ['code' => 'KK-001', 'status' => 'ACTIVE']);
        $this->second = $krama->household($this->admin, null, ['code' => 'KK-002', 'status' => 'ACTIVE']);
        $krama->household($this->admin, null, ['code' => 'KK-NONAKTIF', 'status' => 'INACTIVE']);
        $this->person = $krama->person($this->admin, null, ['household_id' => $this->household, 'name' => 'Made Finansial', 'status' => 'ACTIVE', 'nik' => '1234567890123456']);
        $this->sibling = $krama->person($this->admin, null, ['household_id' => $this->household, 'name' => 'Wayan Saudara', 'status' => 'ACTIVE']);
        $this->finance = app(FinanceManager::class);
        $this->account = $this->finance->account($this->treasurer, ['name' => 'Kas tunai']);
    }

    private function context(?int $id = null): void
    {
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($id ?? $this->a));
    }

    private function role(string $code): User
    {
        $user = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->a, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->a, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'BANJAR']);

        return $user;
    }

    private function login(?User $user = null): void
    {
        $this->actingAs($user ?? $this->treasurer)->withSession(['auth_host' => 'alpha.localhost', 'auth_context' => (string) $this->a]);
    }

    private function event(string $kind = 'IURAN', array $overrides = []): int
    {
        $this->context();
        $type = $this->finance->billingType($this->kesinoman, ['name' => $kind.' '.Str::random(5), 'kind' => $kind, 'default_amount' => '25000']);

        return $this->finance->event($this->kesinoman, $overrides + ['billing_type_id' => $type, 'title' => 'Event '.$kind, 'reference' => (string) Str::uuid()]);
    }

    private function payment(array $extra = []): array
    {
        return $extra + ['cash_account_id' => $this->account, 'transaction_date' => now('Asia/Makassar')->toDateString(), 'reference' => 'BUKTI-001', 'submission_key' => (string) Str::uuid()];
    }

    private function bill(): int
    {
        $event = $this->event();
        $this->finance->issue($this->kesinoman, $event);

        return DB::table('bills')->where('billing_event_id', $event)->value('id');
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function forbidden(callable $action, int $status = 403): void
    {
        try {
            $action();
            $this->fail('Expected HTTP failure.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_iuran_is_one_bill_per_active_household_per_event_and_snapshot_is_stable(): void
    {
        $a = $this->event();
        $b = $this->event();
        $this->finance->issue($this->kesinoman, $a);
        $this->finance->issue($this->kesinoman, $a);
        $this->finance->issue($this->kesinoman, $b);
        $this->assertSame(4, DB::table('bills')->count());
        $this->assertSame(50000, (int) DB::table('bills')->where('kk_id', $this->household)->sum('gross_amount'));
        DB::table('households')->where('id', $this->household)->update(['status' => 'INACTIVE', 'code' => 'CHANGED']);
        DB::table('billing_types')->update(['default_amount' => 90000]);
        $this->finance->issue($this->kesinoman, $a);
        $this->assertDatabaseHas('bills', ['billing_event_id' => $a, 'kk_id' => $this->household, 'household_code' => 'KK-001', 'gross_amount' => 25000]);
        $this->assertSame(4, DB::table('bills')->count());
    }

    public function test_database_enforces_unique_event_household_and_positive_integer_amount(): void
    {
        $bill = $this->bill();
        $row = (array) DB::table('bills')->find($bill);
        unset($row['id']);
        try {
            DB::table('bills')->insert($row);
            $this->fail('Duplicate bill accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }
        try {
            DB::table('bills')->where('id', $bill)->update(['gross_amount' => 0]);
            $this->fail('Zero bill accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('bill_amount', $e->getMessage());
        }
        foreach (['0', '-1', '2.5', '1e3', '1,000', '1000000000000'] as $amount) {
            $this->invalid(fn () => $this->finance->billingType($this->kesinoman, ['name' => 'Invalid', 'kind' => 'IURAN', 'default_amount' => $amount]));
        }
    }

    public function test_duplicate_reference_is_rejected_and_event_amount_is_derived_server_side(): void
    {
        $id = $this->event('IURAN', ['reference' => 'KEMATIAN-A', 'banjar_id' => $this->b, 'status' => 'ISSUED', 'created_by' => $this->super->id]);
        $row = DB::table('billing_events')->find($id);
        $this->assertSame($this->a, $row->banjar_id);
        $this->assertSame($this->kesinoman->id, $row->created_by);
        $this->assertSame('DRAFT', $row->status);
        $this->assertSame(25000, $row->amount);
        $this->invalid(fn () => $this->finance->event($this->kesinoman, ['billing_type_id' => $row->billing_type_id, 'title' => 'Duplicate', 'reference' => 'KEMATIAN-A']));
        $this->assertSame(1, DB::table('billing_events')->count());
    }

    public function test_unit_targets_take_active_direct_members_without_duplicate_households(): void
    {
        $unit = DB::table('organizational_units')->insertGetId(['banjar_id' => $this->a, 'name' => 'Tempekan', 'type' => 'TEMPEKAN']);
        DB::table('household_unit_memberships')->insert(['banjar_id' => $this->a, 'household_id' => $this->household, 'unit_id' => $unit]);
        $event = $this->event('IURAN', ['target' => 'UNIT', 'unit_id' => $unit]);
        $this->finance->issue($this->kesinoman, $event);
        $this->assertSame([$this->household], DB::table('bills')->pluck('kk_id')->all());
    }

    public function test_empty_target_cannot_issue_and_leaves_no_partial_bills(): void
    {
        $event = $this->event();
        DB::table('households')->update(['status' => 'INACTIVE']);
        $this->invalid(fn () => $this->finance->issue($this->kesinoman, $event));
        $this->assertDatabaseHas('billing_events', ['id' => $event, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('bills', 0);
    }

    public function test_dedosan_requires_reason_one_individual_per_household_and_review_before_issue(): void
    {
        $event = $this->event('DEDOSAN');
        $this->invalid(fn () => $this->finance->subject($this->kesinoman, $event, ['subject_krama_id' => $this->person, 'reason' => '']));
        $this->finance->subject($this->kesinoman, $event, ['subject_krama_id' => $this->person, 'kk_id' => $this->second, 'reason' => 'Alasan privat hasil pemeriksaan manual']);
        $this->invalid(fn () => $this->finance->subject($this->kesinoman, $event, ['subject_krama_id' => $this->sibling, 'reason' => 'Alasan individu kedua']));
        $this->invalid(fn () => $this->finance->issue($this->kesinoman, $event));
        $this->finance->review($this->kelian, $event, ['action' => 'approve', 'review_note' => 'Telah diperiksa bersama']);
        $this->forbidden(fn () => $this->finance->subject($this->kesinoman, $event, ['subject_krama_id' => $this->sibling, 'reason' => 'Mengubah setelah disetujui']), 409);
        $this->finance->issue($this->kesinoman, $event);
        $this->finance->issue($this->kesinoman, $event);
        $bill = DB::table('bills')->first();
        $this->assertSame($this->person, $bill->subject_krama_id);
        $this->assertSame($this->household, $bill->kk_id);
        $this->assertSame('Made Finansial', Crypt::decryptString($bill->subject_name));
        $this->assertSame(1, DB::table('bills')->count());
        $this->assertStringNotContainsString('Alasan privat', DB::table('billing_event_subjects')->value('reason'));
        $this->assertStringNotContainsString('Made Finansial', DB::table('audit_logs')->where('action', 'like', 'dedosan.%')->get()->toJson());
    }

    public function test_dedosan_rechecks_master_membership_and_policy_is_snapshotted(): void
    {
        $event = $this->event('DEDOSAN');
        $this->finance->subject($this->kesinoman, $event, ['subject_krama_id' => $this->person, 'reason' => 'Pemeriksaan manual']);
        $this->finance->policy($this->admin, ['dedosan_requires_approval' => false]);
        $this->invalid(fn () => $this->finance->issue($this->kesinoman, $event));
        $this->finance->review($this->kelian, $event, ['action' => 'approve', 'review_note' => 'Pemeriksaan selesai']);
        DB::table('krama')->where('id', $this->person)->update(['household_id' => $this->second]);
        $this->invalid(fn () => $this->finance->issue($this->kesinoman, $event));
        $this->assertDatabaseCount('bills', 0);
        $next = $this->event('DEDOSAN');
        $this->finance->subject($this->kesinoman, $next, ['subject_krama_id' => $this->person, 'reason' => 'Pemeriksaan kejadian baru']);
        $this->finance->issue($this->kesinoman, $next);
        $this->assertDatabaseHas('bills', ['billing_event_id' => $next, 'kk_id' => $this->second]);
    }

    public function test_rejected_dedosan_cannot_issue_and_creator_cannot_approve_even_with_two_roles(): void
    {
        $event = $this->event('DEDOSAN');
        $this->finance->subject($this->kesinoman, $event, ['subject_krama_id' => $this->person, 'reason' => 'Pemeriksaan manual']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $this->a, 'membership_id' => DB::table('banjar_user_memberships')->where('user_id', $this->kesinoman->id)->value('id'), 'role_id' => DB::table('roles')->where('code', 'KELIAN')->value('id'), 'scope_type' => 'BANJAR']);
        $this->forbidden(fn () => $this->finance->review($this->kesinoman, $event, ['action' => 'approve', 'review_note' => 'Menyetujui sendiri']));
        $this->finance->review($this->kelian, $event, ['action' => 'reject', 'review_note' => 'Alasan tidak memadai']);
        $this->forbidden(fn () => $this->finance->issue($this->kesinoman, $event), 409);
    }

    public function test_cash_posts_integer_money_idempotently_and_rejects_overspending(): void
    {
        $input = $this->payment(['direction' => 'IN', 'amount' => '100000', 'reason' => 'Saldo awal berdasarkan buku']);
        $id = $this->finance->cash($this->treasurer, $input);
        $this->assertSame($id, $this->finance->cash($this->treasurer, $input));
        $this->forbidden(fn () => $this->finance->cash($this->treasurer, array_replace($input, ['amount' => '99999'])), 409);
        $this->finance->cash($this->treasurer, $this->payment(['direction' => 'OUT', 'amount' => '75000', 'reason' => 'Pembelian perlengkapan']));
        $this->invalid(fn () => $this->finance->cash($this->treasurer, $this->payment(['direction' => 'OUT', 'amount' => '25001', 'reason' => 'Pengeluaran berlebih'])));
        $this->assertSame(25000, $this->finance->balance($this->account));
        $this->assertDatabaseCount('cash_transactions', 2);
        $this->assertSame('Rp 999.999.999.999', FinanceManager::money('999999999999'));
    }

    public function test_settlement_takes_bill_amount_and_replay_never_double_credits_cash(): void
    {
        $bill = $this->bill();
        $input = $this->payment(['amount' => 1, 'status' => 'PAID', 'banjar_id' => $this->b]);
        $id = $this->finance->settle($this->treasurer, $bill, $input);
        $this->assertSame($id, $this->finance->settle($this->treasurer, $bill, $input));
        $this->forbidden(fn () => $this->finance->settle($this->treasurer, $bill, $this->payment()), 409);
        $this->assertSame(25000, $this->finance->balance($this->account));
        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertDatabaseHas('bills', ['id' => $bill, 'status' => 'PAID']);
        $settlement = (array) DB::table('bill_settlements')->first();
        unset($settlement['id'], $settlement['active_bill_id']);
        $settlement['cash_transaction_id'] = null;
        try {
            DB::table('bill_settlements')->insert($settlement);
            $this->fail('Duplicate active settlement accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }
    }

    public function test_reversal_preserves_original_reopens_bill_and_allows_new_settlement(): void
    {
        $bill = $this->bill();
        $cash = $this->finance->settle($this->treasurer, $bill, $this->payment());
        $before = DB::table('cash_transactions')->find($cash);
        $reverse = $this->finance->reverse($this->treasurer, $cash, ['reason' => 'Kesalahan pencatatan penerimaan']);
        $this->assertSame($reverse, $this->finance->reverse($this->treasurer, $cash, ['reason' => 'Ulang permintaan pembalikan']));
        $this->assertEquals($before, DB::table('cash_transactions')->find($cash));
        $this->assertSame(0, $this->finance->balance($this->account));
        $this->assertDatabaseHas('bills', ['id' => $bill, 'status' => 'UNPAID']);
        $this->forbidden(fn () => $this->finance->reverse($this->treasurer, $reverse, ['reason' => 'Balik dua kali']), 409);
        $this->finance->settle($this->treasurer, $bill, $this->payment());
        $this->assertSame(25000, $this->finance->balance($this->account));
        $this->assertSame(1, DB::table('bill_settlements')->whereNull('reversed_at')->count());
        $this->assertDatabaseCount('bill_settlements', 2);
    }

    public function test_reversal_cannot_overdraw_cash_or_partially_change_settlement(): void
    {
        $bill = $this->bill();
        $cash = $this->finance->settle($this->treasurer, $bill, $this->payment());
        $this->finance->cash($this->treasurer, $this->payment(['direction' => 'OUT', 'amount' => '25000', 'reason' => 'Pengeluaran sah']));
        $this->invalid(fn () => $this->finance->reverse($this->treasurer, $cash, ['reason' => 'Koreksi penerimaan']));
        $this->assertDatabaseHas('bills', ['id' => $bill, 'status' => 'PAID']);
        $this->assertDatabaseCount('cash_transactions', 2);
        $this->assertSame(1, DB::table('bill_settlements')->whereNull('reversed_at')->count());
    }

    public function test_void_refuses_paid_bills_and_preserves_history_after_reversal(): void
    {
        $bill = $this->bill();
        $event = DB::table('bills')->find($bill)->billing_event_id;
        $cash = $this->finance->settle($this->treasurer, $bill, $this->payment());
        $this->invalid(fn () => $this->finance->void($this->kesinoman, $event, ['reason' => 'Kejadian dibatalkan']));
        $this->finance->reverse($this->treasurer, $cash, ['reason' => 'Pembayaran dikembalikan']);
        $this->finance->void($this->kesinoman, $event, ['reason' => 'Kejadian dibatalkan']);
        $this->finance->void($this->kesinoman, $event, ['reason' => 'Kejadian dibatalkan']);
        $this->assertSame(2, DB::table('bills')->where('status', 'VOID')->count());
        $this->forbidden(fn () => $this->finance->settle($this->treasurer, $bill, $this->payment()), 409);
        $this->forbidden(fn () => $this->finance->issue($this->kesinoman, $event), 409);
    }

    public function test_tenant_isolation_on_read_write_relations_and_aggregates(): void
    {
        $bill = $this->bill();
        $this->context($this->b);
        $foreignAccount = DB::table('cash_accounts')->insertGetId(['banjar_id' => $this->b, 'name' => 'Beta PRIVATE']);
        $foreignType = DB::table('billing_types')->insertGetId(['banjar_id' => $this->b, 'name' => 'Beta', 'kind' => 'IURAN', 'default_amount' => 100]);
        $this->context();
        $this->forbidden(fn () => $this->finance->settle($this->treasurer, $bill, $this->payment(['cash_account_id' => $foreignAccount])), 404);
        $this->forbidden(fn () => $this->finance->event($this->kesinoman, ['billing_type_id' => $foreignType, 'title' => 'Wrong tenant', 'reference' => 'WRONG']), 404);
        $this->login();
        $this->get($this->host.'/admin/finance')->assertOk()->assertDontSee('Beta PRIVATE');
        $this->get($this->host.'/admin/finance/cash?account='.$foreignAccount)->assertNotFound();
        try {
            DB::table('billing_events')->where('id', DB::table('bills')->find($bill)->billing_event_id)->update(['billing_type_id' => $foreignType]);
            $this->fail('Cross tenant FK accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('bill_settlements', 0);
    }

    public function test_permissions_module_gate_and_revocation_apply_to_routes_and_services(): void
    {
        $this->login($this->admin);
        $this->get($this->host.'/admin/finance')->assertOk();
        $this->post($this->host.'/admin/finance/accounts', ['name' => 'Forbidden'])->assertForbidden();
        foreach ([$this->admin, $this->kelian, $this->super, $this->role('PENYARIKAN'), $this->role('KRAMA')] as $user) {
            $this->context();
            $this->forbidden(fn () => $this->finance->cash($user, $this->payment(['direction' => 'IN', 'amount' => '100', 'reason' => 'Tidak berwenang'])));
        }
        // Bendahara mengelola kas, tapi tidak lagi otomatis berhak menetapkan tagihan hanya karena peran Petengen.
        $this->forbidden(fn () => $this->finance->event($this->treasurer, ['billing_type_id' => 1, 'title' => 'Tanpa izin', 'reference' => 'NOPERM']));
        $this->login();
        DB::table('banjar_modules')->where('banjar_id', $this->a)->where('code', 'FINANCE')->update(['status' => 'INACTIVE']);
        $this->get($this->host.'/admin')->assertOk()->assertDontSee('href="/admin/finance"', false);
        $this->get($this->host.'/admin/finance')->assertForbidden();
        $this->context();
        $this->forbidden(fn () => $this->finance->account($this->treasurer, ['name' => 'Blocked']));
        DB::table('banjar_modules')->where('banjar_id', $this->a)->where('code', 'FINANCE')->update(['status' => 'ACTIVE']);
        DB::table('banjar_user_memberships')->where('user_id', $this->treasurer->id)->update(['status' => 'INACTIVE']);
        $this->forbidden(fn () => $this->finance->account($this->treasurer, ['name' => 'Revoked']));
    }

    public function test_unit_scoped_kelian_cannot_see_banjar_wide_finance_or_approve(): void
    {
        $event = $this->event('DEDOSAN');
        $unit = DB::table('organizational_units')->insertGetId(['banjar_id' => $this->a, 'name' => 'Regu', 'type' => 'REGU']);
        DB::table('tenant_role_assignments')->where('membership_id', DB::table('banjar_user_memberships')->where('user_id', $this->kelian->id)->value('id'))->update(['scope_type' => 'ORGANIZATIONAL_UNIT', 'unit_id' => $unit]);
        $this->login($this->kelian);
        $this->get($this->host.'/admin/finance')->assertForbidden();
        $this->context();
        $this->forbidden(fn () => $this->finance->review($this->kelian, $event, ['action' => 'approve', 'review_note' => 'Di luar scope']));
    }

    public function test_finance_pages_render_private_without_identity_numbers_and_report_reconciles(): void
    {
        $bill = $this->bill();
        $event = DB::table('bills')->find($bill)->billing_event_id;
        $type = DB::table('billing_events')->find($event)->billing_type_id;
        $this->finance->settle($this->treasurer, $bill, $this->payment());
        $this->finance->cash($this->treasurer, $this->payment(['direction' => 'OUT', 'amount' => '10000', 'reason' => 'Pembelian barang']));
        $this->login();
        foreach (['', '/cash', '/settings', '/bills', '/report', '/events/'.$event, '/bills/'.$bill] as $path) {
            $this->get($this->host.'/admin/finance'.$path)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertDontSee('1234567890123456');
        }
        $this->login($this->kesinoman);
        $this->get($this->host.'/admin/finance/events/create?type='.$type)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertDontSee('1234567890123456');
        $this->login();
        $this->get($this->host.'/admin/finance/report')->assertSee('Rp 25.000')->assertSee('Rp 10.000')->assertSee('Rp 15.000')->assertSee('seluruh periode');
        $this->get($this->host.'/admin/finance/report?from=2026-09-20&to=2026-09-01')->assertSessionHasErrors('to');
        $this->post($this->host.'/admin/finance/cash', $this->payment(['direction' => 'OUT', 'amount' => '999999', 'reason' => 'RAHASIA FINANSIAL']))->assertSessionHasErrors();
        $this->assertNull(session()->getOldInput('reason'));
    }

    public function test_issue_batches_more_than_250_households_without_skips_or_duplicates(): void
    {
        $rows = [];
        for ($i = 1; $i <= 260; $i++) {
            $rows[] = ['banjar_id' => $this->a, 'code' => 'BATCH-'.$i, 'status' => 'ACTIVE'];
        }
        DB::table('households')->insert($rows);
        $event = $this->event();
        $this->finance->issue($this->kesinoman, $event);
        $this->finance->issue($this->kesinoman, $event);
        $this->assertSame(262, DB::table('bills')->count());
        $this->assertSame(262, DB::table('bills')->distinct()->count('kk_id'));
        $this->assertSame(6550000, (int) DB::table('bills')->sum('gross_amount'));
    }

    public function test_report_opening_period_and_closing_exclude_future_period_transactions(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 08:00:00', 'UTC'));
        $this->finance->cash($this->treasurer, $this->payment(['direction' => 'IN', 'amount' => '100000', 'transaction_date' => '2026-08-31', 'reason' => 'Saldo dari periode sebelumnya']));
        $this->finance->cash($this->treasurer, $this->payment(['direction' => 'OUT', 'amount' => '30000', 'transaction_date' => '2026-09-05', 'reason' => 'Pengeluaran dalam periode']));
        $this->finance->cash($this->treasurer, $this->payment(['direction' => 'IN', 'amount' => '20000', 'transaction_date' => '2026-09-16', 'reason' => 'Pemasukan setelah periode']));
        $this->login();
        $response = $this->get($this->host.'/admin/finance/report?from=2026-09-01&to=2026-09-10')->assertOk();
        $account = $response->viewData('accounts')->first();
        $this->assertSame(100000, $account->opening);
        $this->assertSame(0, $account->incoming);
        $this->assertSame(30000, $account->outgoing);
        $this->assertSame(70000, $account->closing);
        $this->travelBack();
    }
}

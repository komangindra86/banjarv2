<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Krama\KramaManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinanceFixture
{
    public static function create(): array
    {
        if (DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Finance fixtures are restricted to banjarv2_testing.');
        }
        (new RoleSeeder)->run();
        $password = 'Qa9!'.Str::random(26);
        $super = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        $tenant = app(TenantManager::class)->create($super, ['name' => 'Banjar Uji Keuangan', 'slug' => 'financeqa', 'admin_name' => 'Admin QA', 'admin_email' => 'admin-finance@example.test', 'admin_password' => $password, 'admin_password_confirmation' => $password]);
        app(TenantManager::class)->module($super, $tenant, 'KRAMA', ['status' => 'ACTIVE']);
        app(TenantManager::class)->module($super, $tenant, 'FINANCE', ['status' => 'ACTIVE']);
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($tenant));
        $admin = User::where('email', 'admin-finance@example.test')->firstOrFail();
        $users = [];
        foreach (['PETENGEN', 'KELIAN', 'KESINOMAN'] as $code) {
            $user = User::factory()->create(['name' => $code.' QA', 'email' => strtolower($code).'-finance@example.test', 'password' => $password]);
            $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
            DB::table('tenant_role_assignments')->insert(['banjar_id' => $tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'BANJAR']);
            $users[$code] = $user->id;
        }
        $treasurer = User::findOrFail($users['PETENGEN']);
        $kesinoman = User::findOrFail($users['KESINOMAN']);
        $krama = app(KramaManager::class);
        $people = [];
        for ($i = 1; $i <= 3; $i++) {
            $kk = $krama->household($admin, null, ['code' => 'KK-QA-00'.$i, 'status' => 'ACTIVE']);
            $person = $krama->person($admin, null, ['household_id' => $kk, 'name' => 'Krama Uji '.$i, 'status' => 'ACTIVE']);
            // Dedosan/iuran hanya dikenakan ke kepala keluarga; jadikan satu-satunya anggota sebagai kepala keluarga.
            DB::table('households')->where('id', $kk)->update(['head_krama_id' => $person]);
            $people[] = $person;
        }
        $finance = app(FinanceManager::class);
        $account = $finance->account($treasurer, ['name' => 'Kas tunai Banjar']);
        // Kesinoman menetapkan/menerbitkan iuran dan dedosan; Petengen mengelola kas (Master Prompt §27-29).
        $type = $finance->billingType($kesinoman, ['name' => 'Iuran bulanan', 'kind' => 'IURAN', 'default_amount' => 50000]);
        $event = $finance->event($kesinoman, ['billing_type_id' => $type, 'title' => 'Iuran September 2026', 'reference' => 'IURAN-2026-09', 'event_date' => now('Asia/Makassar')->toDateString()]);
        $dedosanType = $finance->billingType($kesinoman, ['name' => 'Dedosan kegiatan', 'kind' => 'DEDOSAN', 'default_amount' => 100000]);
        $dedosan = $finance->event($kesinoman, ['billing_type_id' => $dedosanType, 'title' => 'Dedosan pemeriksaan manual', 'reference' => 'DEDOSAN-QA-001', 'event_date' => now('Asia/Makassar')->toDateString()]);

        return compact('tenant', 'password', 'users', 'account', 'type', 'event', 'dedosan', 'people');
    }

    // QRIS hanya dibayar warga sendiri (payment.self): akun Krama yang terhubung ke kepala keluarga tagihan ini.
    public static function payer(int $tenant, int $billId): User
    {
        $household = DB::table('bills')->where('id', $billId)->value('kk_id');
        $head = DB::table('households')->where('id', $household)->value('head_krama_id');
        if ($existing = DB::table('krama')->where('id', $head)->value('user_id')) {
            return User::findOrFail($existing);
        }
        $user = User::factory()->create();
        $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
        DB::table('tenant_role_assignments')->insert(['banjar_id' => $tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'KRAMA')->value('id'), 'scope_type' => 'BANJAR']);
        DB::table('krama')->where('id', $head)->update(['user_id' => $user->id]);

        return $user;
    }
}

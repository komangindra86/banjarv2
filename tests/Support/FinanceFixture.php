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
        foreach (['PETENGEN', 'KELIAN'] as $code) {
            $user = User::factory()->create(['name' => $code.' QA', 'email' => strtolower($code).'-finance@example.test', 'password' => $password]);
            $member = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $tenant, 'user_id' => $user->id, 'status' => 'ACTIVE']);
            DB::table('tenant_role_assignments')->insert(['banjar_id' => $tenant, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'BANJAR']);
            $users[$code] = $user->id;
        }
        $treasurer = User::findOrFail($users['PETENGEN']);
        $krama = app(KramaManager::class);
        $people = [];
        for ($i = 1; $i <= 3; $i++) {
            $kk = $krama->household($admin, null, ['code' => 'KK-QA-00'.$i, 'status' => 'ACTIVE']);
            $people[] = $krama->person($admin, null, ['household_id' => $kk, 'name' => 'Krama Uji '.$i, 'status' => 'ACTIVE']);
        }
        $finance = app(FinanceManager::class);
        $account = $finance->account($treasurer, ['name' => 'Kas tunai Banjar']);
        $type = $finance->billingType($treasurer, ['name' => 'Iuran bulanan', 'kind' => 'IURAN', 'default_amount' => 50000]);
        $event = $finance->event($treasurer, ['billing_type_id' => $type, 'title' => 'Iuran September 2026', 'reference' => 'IURAN-2026-09']);
        $dedosanType = $finance->billingType($treasurer, ['name' => 'Dedosan kegiatan', 'kind' => 'DEDOSAN', 'default_amount' => 100000]);
        $dedosan = $finance->event($treasurer, ['billing_type_id' => $dedosanType, 'title' => 'Dedosan pemeriksaan manual', 'reference' => 'DEDOSAN-QA-001']);

        return compact('tenant', 'password', 'users', 'account', 'type', 'event', 'dedosan', 'people');
    }
}

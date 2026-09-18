<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Penetapan iuran/patus dan dedosan berpindah dari Petengen ke Kesinoman (revisi Master Prompt).
        // Seeder hanya insert; permission yang sudah tercabut dari Petengen harus dihapus eksplisit.
        $petengen = DB::table('roles')->where('code', 'PETENGEN')->value('id');
        if ($petengen) {
            $toRemove = DB::table('permissions')->whereIn('code', ['billing.issue', 'dedosan.issue'])->pluck('id');
            DB::table('role_permissions')->where('role_id', $petengen)->whereIn('permission_id', $toRemove)->delete();
        }
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        $petengen = DB::table('roles')->where('code', 'PETENGEN')->value('id');
        foreach (['billing.issue', 'dedosan.issue'] as $code) {
            $permission = DB::table('permissions')->where('code', $code)->value('id');
            if ($petengen && $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $petengen, 'permission_id' => $permission]);
            }
        }
    }
};

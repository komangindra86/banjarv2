<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Petengen mencatat pembayaran tunai; QRIS hanya dibuat warga sendiri dari portal (payment.self).
    public function up(): void
    {
        $role = DB::table('roles')->where('code', 'PETENGEN')->value('id');
        $permission = DB::table('permissions')->where('code', 'payment.create')->value('id');
        if ($role && $permission) {
            DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->delete();
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('code', 'PETENGEN')->value('id');
        $permission = DB::table('permissions')->where('code', 'payment.create')->value('id');
        if ($role && $permission) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }
};

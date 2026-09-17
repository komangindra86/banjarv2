<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'SUPER_ADMIN' => ['Super Admin Bali Santih', 'PLATFORM', ['platform.manage']],
            'ADMIN_BANJAR' => ['Admin Banjar', 'TENANT', ['dashboard.view', 'website.manage', 'activity.manage', 'settings.manage', 'audit.view', 'krama.view', 'krama.manage', 'units.manage', 'krama.configure', 'change.verify', 'letter.view', 'letter.configure', 'finance.view', 'finance.configure']],
            'KELIAN' => ['Kelian Adat', 'TENANT', ['dashboard.view', 'audit.view', 'krama.view', 'change.approve', 'letter.view', 'letter.approve', 'finance.view', 'dedosan.approve']],
            'PETENGEN' => ['Petengen / Bendahara', 'TENANT', ['dashboard.view', 'finance.view', 'cash.manage', 'billing.issue', 'dedosan.issue']],
            'PENYARIKAN' => ['Penyarikan / Sekretaris', 'TENANT', ['dashboard.view', 'website.manage', 'activity.manage', 'letter.view', 'letter.verify']],
            'KESINOMAN' => ['Kesinoman', 'TENANT', ['dashboard.view', 'krama.view', 'krama.manage', 'change.verify']],
            'KRAMA' => ['Krama', 'TENANT', ['dashboard.view', 'krama.self', 'letter.submit']],
        ];
        foreach (['ADMIN_BANJAR', 'KELIAN', 'PETENGEN'] as $code) {
            $roles[$code][2][] = 'payment.view';
        }
        $roles['PETENGEN'][2][] = 'payment.create';
        $roles['KRAMA'][2][] = 'payment.self';
        // Fase 7: manual withdrawal. Platform transfer rights stay separate from tenant roles.
        foreach (['ADMIN_BANJAR', 'KELIAN', 'PETENGEN'] as $code) {
            $roles[$code][2][] = 'withdrawal.view';
        }
        array_push($roles['PETENGEN'][2], 'withdrawal.request', 'bank.request');
        $roles['KELIAN'][2][] = 'withdrawal.approve';
        $roles['ADMIN_BANJAR'][2][] = 'withdrawal.configure';
        array_push($roles['SUPER_ADMIN'][2], 'platform.withdrawal.process', 'bank.verify');
        // Fase 8: activity operations and attendance. Recording may be unit-scoped; verification is Banjar-wide.
        foreach (['ADMIN_BANJAR', 'KELIAN', 'PETENGEN', 'PENYARIKAN', 'KESINOMAN'] as $code) {
            $roles[$code][2][] = 'activity.view';
        }
        foreach (['ADMIN_BANJAR', 'PENYARIKAN', 'KESINOMAN'] as $code) {
            $roles[$code][2][] = 'attendance.record';
        }
        foreach (['KELIAN', 'PENYARIKAN'] as $code) {
            $roles[$code][2][] = 'attendance.verify';
        }
        $roles['KRAMA'][2][] = 'activity.self';
        // Fase 9: aset. Penghapusan resmi dibatasi Admin Banjar; Petengen mengelola inventaris harian.
        foreach (['ADMIN_BANJAR', 'PETENGEN'] as $code) {
            array_push($roles[$code][2], 'asset.view', 'asset.manage');
        }
        $roles['ADMIN_BANJAR'][2][] = 'asset.dispose';
        foreach ($roles as $code => [$name, $scope, $permissions]) {
            DB::table('roles')->updateOrInsert(['code' => $code], compact('name', 'scope'));
            $role = DB::table('roles')->where('code', $code)->value('id');
            foreach ($permissions as $permission) {
                DB::table('permissions')->insertOrIgnore(['code' => $permission]);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $permission)->value('id')]);
            }
        }
    }
}

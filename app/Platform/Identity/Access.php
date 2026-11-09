<?php

namespace App\Platform\Identity;

use App\Models\User;
use App\Platform\Support\RequestMemo;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Access
{
    public function __construct(private CurrentBanjar $tenant) {}

    // Izin yang tidak bergantung pada cakupan unit: cukup punya peran apa pun (BANJAR atau unit) yang memuatnya.
    private const UNIT_SCOPED = ['dashboard.view', 'krama.view', 'krama.manage', 'change.verify', 'change.approve', 'letter.view', 'letter.verify', 'letter.approve', 'activity.view', 'attendance.record', 'billing.issue', 'dedosan.issue'];

    public function platform(?User $user, string $permission = 'platform.manage'): bool
    {
        return $this->active($user) && in_array($permission, RequestMemo::remember('platform:'.$user->id, fn () => DB::table('platform_role_assignments as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.user_id', $user->id)->where('r.scope', 'PLATFORM')->distinct()->pluck('p.code')->all()), true);
    }

    public function tenant(?User $user, string $permission = 'dashboard.view'): bool
    {
        return $this->assignments($user, $permission)->isNotEmpty();
    }

    public function assignments(?User $user, string $permission): Collection
    {
        if (! $this->active($user)) {
            return collect();
        }

        $tenantId = $this->tenant->id();
        // Semua peran + izin akun di Banjar ini dibaca sekali per request, lalu disaring per izin.
        $rows = RequestMemo::remember('tenant-access:'.$tenantId.':'.$user->id, fn () => DB::table('banjar_user_memberships as m')
            ->join('banjars as b', 'b.id', '=', 'm.banjar_id')->where('b.status', 'ACTIVE')
            ->join('tenant_role_assignments as a', fn ($j) => $j->on('a.membership_id', '=', 'm.id')->on('a.banjar_id', '=', 'm.banjar_id'))
            ->join('roles as r', 'r.id', '=', 'a.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('m.banjar_id', $tenantId)->where('m.user_id', $user->id)->where('m.status', 'ACTIVE')
            ->where('r.scope', 'TENANT')
            ->select('a.*', 'p.code as permission_code')->get()->groupBy('permission_code'));
        $unitScoped = in_array($permission, self::UNIT_SCOPED, true);

        return collect($rows->get($permission, []))
            ->filter(fn ($row) => $unitScoped || $row->scope_type === 'BANJAR')
            ->map(function ($row) {
                $row = clone $row;
                unset($row->permission_code);

                return $row;
            })->values();
    }

    public function requirePlatform(User $user): void
    {
        abort_unless($this->platform($user), 403);
    }

    private function active(?User $user): bool
    {
        return $user && RequestMemo::remember('active:'.$user->id, fn () => DB::table('users')->where('id', $user->id)->where('status', 'ACTIVE')->exists());
    }

    public function requireTenant(User $user, string $permission): void
    {
        abort_unless($this->tenant($user, $permission), 403);
    }
}

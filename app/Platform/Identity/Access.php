<?php

namespace App\Platform\Identity;

use App\Models\User;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Access
{
    public function __construct(private CurrentBanjar $tenant) {}

    public function platform(?User $user, string $permission = 'platform.manage'): bool
    {
        return $this->active($user) && DB::table('platform_role_assignments as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.user_id', $user->id)->where('r.scope', 'PLATFORM')->where('p.code', $permission)->exists();
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

        return DB::table('banjar_user_memberships as m')
            ->join('banjars as b', 'b.id', '=', 'm.banjar_id')->where('b.status', 'ACTIVE')
            ->join('tenant_role_assignments as a', fn ($j) => $j->on('a.membership_id', '=', 'm.id')->on('a.banjar_id', '=', 'm.banjar_id'))
            ->join('roles as r', 'r.id', '=', 'a.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('m.banjar_id', $this->tenant->id())->where('m.user_id', $user->id)->where('m.status', 'ACTIVE')
            ->where('r.scope', 'TENANT')->where('p.code', $permission)
            ->when(! in_array($permission, ['dashboard.view', 'krama.view', 'krama.manage', 'change.verify', 'change.approve', 'letter.view', 'letter.verify', 'letter.approve', 'activity.view', 'attendance.record']), fn ($q) => $q->where('a.scope_type', 'BANJAR'))
            ->select('a.*')->get();
    }

    public function requirePlatform(User $user): void
    {
        abort_unless($this->platform($user), 403);
    }

    private function active(?User $user): bool
    {
        return $user && DB::table('users')->where('id', $user->id)->where('status', 'ACTIVE')->exists();
    }

    public function requireTenant(User $user, string $permission): void
    {
        abort_unless($this->tenant($user, $permission), 403);
    }
}

<?php

namespace App\Modules\Portal;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Support\RequestMemo;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// §53: portal krama pusat. Satu alamat untuk semua Banjar; Banjar aktif disimpan di sesi dan diperiksa ulang tiap request.
final class PortalManager
{
    public function __construct(private Audit $audit) {}

    // Banjar yang boleh dibuka akun ini: keanggotaan aktif, peran dengan krama.self, modul KRAMA & PORTAL aktif.
    // Dibaca sekali per request: middleware portal memeriksa Banjar aktif, menu memeriksa perlu "Ganti banjar".
    public function banjars(User $user): Collection
    {
        return RequestMemo::remember('portal-banjars:'.$user->id, fn () => $this->eligible($user)->orderBy('b.name')->get());
    }

    public function banjar(User $user, int $id): ?object
    {
        return $this->banjars($user)->first(fn ($banjar) => (int) $banjar->id === $id);
    }

    // Mengikat sesi portal ke satu Banjar; mengembalikan halaman tujuan setelah masuk.
    public function enter(Session $session, User $user, object $banjar): string
    {
        $session->put(['portal_banjar_id' => (int) $banjar->id, 'auth_context' => (string) $banjar->id]);
        $this->audit->record((int) $banjar->id, $user->id, 'auth.login', 'user', $user->id, [], ['channel' => 'PORTAL']);
        $intended = $session->pull('portal_intended');

        return is_string($intended) && preg_match('#^/portal(/[A-Za-z0-9/_-]*)?$#', $intended) ? $intended : '/portal/beranda';
    }

    private function eligible(User $user): Builder
    {
        $module = fn (string $code) => fn ($q) => $q->from('banjar_modules as bm')->whereColumn('bm.banjar_id', 'b.id')->where('bm.code', $code)->where('bm.status', 'ACTIVE');

        return DB::table('banjars as b')->where('b.status', 'ACTIVE')
            ->whereExists(fn ($q) => $q->from('users as u')->where('u.id', $user->id)->where('u.status', 'ACTIVE'))
            ->whereExists(fn ($q) => $q->from('banjar_user_memberships as m')
                ->join('tenant_role_assignments as a', fn ($j) => $j->on('a.membership_id', '=', 'm.id')->on('a.banjar_id', '=', 'm.banjar_id'))
                ->join('roles as r', 'r.id', '=', 'a.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->whereColumn('m.banjar_id', 'b.id')->where('m.user_id', $user->id)->where('m.status', 'ACTIVE')
                ->where('r.scope', 'TENANT')->where('a.scope_type', 'BANJAR')->where('p.code', 'krama.self'))
            ->whereExists($module('KRAMA'))->whereExists($module('PORTAL'))
            ->select('b.*');
    }
}

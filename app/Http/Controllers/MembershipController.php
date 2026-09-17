<?php

namespace App\Http\Controllers;

use App\Platform\Identity\MembershipManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MembershipController extends Controller
{
    public function index(int $id)
    {
        $banjar = DB::table('banjars')->where('id', $id)->first();
        abort_unless($banjar, 404);
        $members = DB::table('banjar_user_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('tenant_role_assignments as a', fn ($j) => $j->on('a.membership_id', '=', 'm.id')->on('a.banjar_id', '=', 'm.banjar_id'))
            ->where('m.banjar_id', $id)->select('m.*', 'u.name', 'u.email', 'a.role_id', 'a.scope_type', 'a.unit_id', 'a.include_descendants')->orderBy('m.id')->paginate(20);

        return view('platform.members', ['banjar' => $banjar, 'members' => $members, 'roles' => DB::table('roles')->where('scope', 'TENANT')->get(), 'units' => DB::table('organizational_units')->where('banjar_id', $id)->where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(Request $request, int $id, MembershipManager $manager)
    {
        $manager->create($request->user(), $id, $request->all());

        return back()->with('success', 'Akun pengurus ditambahkan.');
    }

    public function update(Request $request, int $id, int $membership, MembershipManager $manager)
    {
        $manager->update($request->user(), $id, $membership, $request->all());

        return back()->with('success', 'Akses pengurus diperbarui.');
    }
}

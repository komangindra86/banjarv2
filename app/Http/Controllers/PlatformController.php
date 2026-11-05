<?php

namespace App\Http\Controllers;

use App\Platform\Tenants\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformController extends Controller
{
    public function index(Request $request)
    {
        $search = mb_substr((string) $request->query('q', ''), 0, 100);
        // Logo Banjar (Publik, modul Website aktif) untuk kartu; tanpa logo kartu memakai logo Bali Santih.
        $banjars = DB::table('banjars as b')->leftJoin('tenant_domains as d', 'd.id', '=', 'b.primary_domain_id')
            ->leftJoin('website_profiles as wp', 'wp.banjar_id', '=', 'b.id')
            ->leftJoin('media_files as logo', fn ($j) => $j->on('logo.id', '=', 'wp.logo_id')->where('logo.visibility', 'PUBLIC')->where('logo.mime', 'like', 'image/%'))
            ->leftJoin('banjar_modules as web', fn ($j) => $j->on('web.banjar_id', '=', 'b.id')->where('web.code', 'WEBSITE')->where('web.status', 'ACTIVE'))
            ->when($search !== '', fn ($q) => $q->where('b.name', 'like', '%'.$search.'%'))
            ->select('b.*', 'd.domain', DB::raw('CASE WHEN web.id IS NOT NULL THEN logo.id END as logo_id'))->orderByDesc('b.id')->paginate(12)->withQueryString();

        return view('platform.index', compact('banjars', 'search'));
    }

    public function create()
    {
        return view('platform.create');
    }

    public function store(Request $request, TenantManager $manager)
    {
        $id = $manager->create($request->user(), $request->all());

        return redirect('/platform/banjars/'.$id)->with('success', 'Banjar dan akun pengurus berhasil dibuat.');
    }

    public function show(int $id)
    {
        $banjar = DB::table('banjars')->where('id', $id)->first();
        abort_unless($banjar, 404);

        return view('platform.show', ['banjar' => $banjar, 'domains' => DB::table('tenant_domains')->where('banjar_id', $id)->get(), 'modules' => DB::table('banjar_modules')->where('banjar_id', $id)->get()]);
    }

    public function status(Request $request, int $id, TenantManager $manager)
    {
        $manager->status($request->user(), $id, $request->all());

        return back()->with('success', 'Status Banjar diperbarui.');
    }

    public function module(Request $request, int $id, string $code, TenantManager $manager)
    {
        $manager->module($request->user(), $id, $code, $request->all());

        return back()->with('success', 'Pengaturan modul disimpan.');
    }

    public function audit()
    {
        return view('admin.audit', ['logs' => DB::table('audit_logs')->orderByDesc('id')->paginate(25)]);
    }
}

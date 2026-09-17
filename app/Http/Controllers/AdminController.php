<?php

namespace App\Http\Controllers;

use App\Modules\Website\PublicContent;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use App\Platform\Tenants\SettingsManager;
use Illuminate\Http\Request;

final class AdminController extends Controller
{
    public function index(TenantQuery $query, PublicContent $public)
    {
        return view('admin.index', ['modules' => $query->table('banjar_modules')->get(), 'counts' => ['Pengumuman aktif' => $public->published('pengumuman')->count(), 'Agenda mendatang' => $public->published('agenda')->where('starts_at', '>=', now())->count(), 'Berita terbit' => $public->published('berita')->count()]]);
    }

    public function settings(TenantQuery $query)
    {
        return view('admin.settings', ['settings' => $query->table('banjar_settings')->first()]);
    }

    public function update(Request $request, int $id, SettingsManager $manager)
    {
        $manager->update($request->user(), $id, $request->all());

        return back()->with('success', 'Pengaturan website disimpan.');
    }

    public function audit(TenantQuery $query)
    {
        return view('admin.audit', ['logs' => $query->table('audit_logs')->orderByDesc('id')->paginate(25)]);
    }

    public function website(Request $request, Access $access, ModuleGate $gate)
    {
        $access->requireTenant($request->user(), 'website.manage');
        $gate->authorize('WEBSITE');

        return view('admin.content.hub');
    }

    public function activity(Request $request, Access $access, ModuleGate $gate)
    {
        $access->requireTenant($request->user(), 'activity.manage');
        $gate->authorize('ACTIVITY');

        return app(WebsiteAdminController::class)->index($request, 'agenda');
    }
}

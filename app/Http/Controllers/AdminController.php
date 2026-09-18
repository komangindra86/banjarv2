<?php

namespace App\Http\Controllers;

use App\Modules\Activity\ActivityAccess;
use App\Modules\Website\PublicContent;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use App\Platform\Tenants\SettingsManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminController extends Controller
{
    public function index(Request $request, TenantQuery $query, PublicContent $public, Access $access, ModuleGate $gate, CurrentBanjar $tenant, ActivityAccess $activityAccess)
    {
        $user = $request->user();
        $tenantId = $tenant->id();

        // Each entry is only computed when the viewer actually holds the matching permission, so a scoped
        // role (e.g. Kesinoman assigned to one unit) never sees a Banjar-wide number that isn't theirs to act on.
        $actions = [
            'dedosan' => $access->tenant($user, 'dedosan.approve') && $gate->allows('FINANCE') && $gate->allows('KRAMA')
                ? DB::table('billing_events')->where('banjar_id', $tenantId)->where('kind', 'DEDOSAN')->where('status', 'DRAFT')->where('requires_approval', true)->count() : 0,
            'withdrawal' => $access->tenant($user, 'withdrawal.approve') && $gate->allows('PAYMENT') && $gate->allows('FINANCE') && $gate->allows('KRAMA')
                ? DB::table('withdrawals')->where('banjar_id', $tenantId)->where('status', 'APPROVAL_PENDING')->where('requested_by', '<>', $user->id)->count() : 0,
            'billing_issue' => $gate->allows('FINANCE') && $gate->allows('KRAMA') && ($access->tenant($user, 'billing.issue') || $access->tenant($user, 'dedosan.issue')),
            'letter' => $gate->allows('ADMINISTRATION') && $gate->allows('KRAMA')
                ? ($access->tenant($user, 'letter.verify') ? DB::table('letter_requests')->where('banjar_id', $tenantId)->where('status', 'SUBMITTED')->count() : 0)
                    + ($access->tenant($user, 'letter.approve') ? DB::table('letter_requests')->where('banjar_id', $tenantId)->where('status', 'VERIFIED')->count() : 0) : 0,
            'change' => $gate->allows('KRAMA')
                ? ($access->tenant($user, 'change.verify') ? DB::table('krama_change_requests')->where('banjar_id', $tenantId)->where('status', 'SUBMITTED')->count() : 0)
                    + ($access->tenant($user, 'change.approve') ? DB::table('krama_change_requests')->where('banjar_id', $tenantId)->where('status', 'VERIFIED')->count() : 0) : 0,
        ];

        // Unit-scoped attendance: reuses ActivityAccess::participants() per candidate activity so the count
        // never exceeds what the viewer's own unit assignment already permits them to record.
        $actions['attendance'] = 0;
        if ($activityAccess->can($user, 'attendance.record')) {
            foreach (DB::table('activities')->where('banjar_id', $tenantId)->where('attendance_status', 'DRAFT')->pluck('id') as $activityId) {
                if ($activityAccess->participants($user, $activityId, 'attendance.record')->where('status', 'BELUM_DIVERIFIKASI')->exists()) {
                    $actions['attendance']++;
                }
            }
        }

        return view('admin.index', ['modules' => $query->table('banjar_modules')->get(), 'actions' => $actions, 'counts' => ['Pengumuman aktif' => $public->published('pengumuman')->count(), 'Agenda mendatang' => $public->published('agenda')->where('starts_at', '>=', now())->count(), 'Berita terbit' => $public->published('berita')->count()]]);
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

<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Portal\PortalInfoManager;
use App\Modules\Portal\PortalManager;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class PortalController extends Controller
{
    public function __construct(private Access $access, private ModuleGate $gate, private TenantQuery $query) {}

    // Beranda aggregates read-only summaries from other modules' own self-service data.
    // It never copies or duplicates master/bill/activity records (Rule: PORTAL is an interface, not a data owner).
    public function home(Request $request)
    {
        $user = $request->user();
        $this->access->requireTenant($user, 'krama.self');
        $this->gate->authorize('KRAMA');

        $self = $this->query->table('krama')->where('user_id', $user->id)->where('status', 'ACTIVE')
            ->whereIn('household_id', $this->query->table('households')->where('status', 'ACTIVE')->select('id'))->first();

        $canBill = $self && $this->gate->allows('FINANCE') && $this->gate->allows('PAYMENT') && $this->access->tenant($user, 'payment.self');
        $bill = $canBill ? $this->query->table('bills')->where('kk_id', $self->household_id)->where('status', 'UNPAID')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(gross_amount), 0) AS total')->first() : null;

        $canActivity = $self && $this->gate->allows('ACTIVITY', 'ACTIVE') && $this->access->tenant($user, 'activity.self');
        $activity = $canActivity ? $this->query->table('activity_participants')->join('activities as a', 'a.id', '=', 'activity_participants.activity_id')
            ->where(fn ($q) => $q->where('activity_participants.krama_id', $self->id)
                ->orWhere(fn ($q) => $q->where('activity_participants.basis', 'HOUSEHOLD')->where('activity_participants.household_id', $self->household_id)))
            ->where('a.status', '<>', 'ARCHIVED')->where('a.attendance_status', '<>', 'NONE')->where('a.starts_at', '>=', now())
            ->orderBy('a.starts_at')->select('a.title', 'a.starts_at', 'a.location')->first() : null;
        // Tanpa presensi untuk KK ini, tetap tampilkan kegiatan Banjar terdekat dari jadwal.
        $activity ??= $canActivity ? $this->query->table('activities')->where('status', 'PUBLISHED')->where('published_at', '<=', now())
            ->where('visibility', 'PUBLIC')->where('starts_at', '>=', now())
            ->orderBy('starts_at')->select('title', 'starts_at', 'location')->first() : null;

        $canLetter = $this->gate->allows('ADMINISTRATION') && $this->access->tenant($user, 'letter.submit');
        $pendingLetters = $canLetter ? $this->query->table('letter_requests')->where('requester_id', $user->id)->whereIn('status', ['SUBMITTED', 'VERIFIED'])->count() : null;

        // Laporan kas sangkep terakhir & pembayaran terakhir (hanya baca; lihat PortalInfoManager).
        $canFinance = $this->gate->allows('FINANCE');
        $report = $canFinance ? app(PortalInfoManager::class)->reports()->first() : null;
        $lastPayment = $canFinance && $self ? app(PortalInfoManager::class)->payments($self)->first() : null;

        return view('portal.home', compact('self', 'canBill', 'bill', 'canActivity', 'activity', 'canLetter', 'pendingLetters', 'canFinance', 'report', 'lastPayment'));
    }

    // §53: portal pusat — warga dengan lebih dari satu Banjar memilih Banjar yang dibuka.
    public function choose(Request $request, PortalManager $portal)
    {
        $user = $this->portalUser($request);
        if (! $user) {
            return redirect('/login');
        }

        return view('portal.choose', ['banjars' => $portal->banjars($user), 'active' => (int) $request->session()->get('portal_banjar_id')]);
    }

    public function select(Request $request, PortalManager $portal)
    {
        $user = $this->portalUser($request);
        if (! $user) {
            return redirect('/login');
        }
        $banjar = $portal->banjar($user, (int) $request->validate(['banjar_id' => ['required', 'integer']])['banjar_id']);
        if (! $banjar) {
            throw ValidationException::withMessages(['banjar_id' => 'Banjar tidak tersedia untuk akun ini.']);
        }
        $request->session()->regenerateToken();

        return redirect($portal->enter($request->session(), $user, $banjar));
    }

    private function portalUser(Request $request): ?User
    {
        abort_unless($request->attributes->get('portal'), 404);
        $user = Auth::guard('web')->user();
        if ($user && $request->session()->get('auth_host') !== $request->getHost()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        return $user;
    }
}

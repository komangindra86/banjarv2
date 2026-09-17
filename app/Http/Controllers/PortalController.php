<?php

namespace App\Http\Controllers;

use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;

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

        $canLetter = $this->gate->allows('ADMINISTRATION') && $this->access->tenant($user, 'letter.submit');
        $pendingLetters = $canLetter ? $this->query->table('letter_requests')->where('requester_id', $user->id)->whereIn('status', ['SUBMITTED', 'VERIFIED'])->count() : null;

        return view('portal.home', compact('self', 'canBill', 'bill', 'canActivity', 'activity', 'canLetter', 'pendingLetters'));
    }
}

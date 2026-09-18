<?php

namespace App\Http\Controllers;

use App\Modules\Activity\ActivityAccess;
use App\Modules\Activity\ActivityManager;
use App\Modules\Activity\DedosanCandidateManager;
use App\Modules\Activity\MinutesManager;
use App\Modules\Krama\KramaAccess;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ActivityController extends Controller
{
    public function __construct(private ActivityAccess $access, private ActivityManager $manager, private TenantQuery $query) {}

    private function flags(Request $request): array
    {
        $user = $request->user();

        return ['canManage' => $this->access->can($user, 'activity.manage'), 'canRecord' => $this->access->can($user, 'attendance.record'),
            'canVerify' => $this->access->can($user, 'attendance.verify'),
            'canDedosan' => app(ModuleGate::class)->allows('FINANCE') && app(Access::class)->tenant($user, 'dedosan.issue')];
    }

    public function index(Request $request)
    {
        $this->access->authorize($request->user(), 'activity.view');
        $upcoming = $request->query('periode') !== 'lalu';
        $activities = $this->query->table('activities')->where('status', '<>', 'ARCHIVED')
            ->where('starts_at', $upcoming ? '>=' : '<', now()->subHours(12))->orderBy('starts_at', $upcoming ? 'asc' : 'desc')->paginate(15)->withQueryString();
        $counts = $this->query->table('activity_participants')->whereIn('activity_id', $activities->pluck('id')->all() ?: [0])
            ->selectRaw("activity_id, COUNT(*) AS total, SUM(status = 'HADIR') AS hadir, SUM(status = 'BELUM_DIVERIFIKASI') AS belum")->groupBy('activity_id')->get()->keyBy('activity_id');

        return view('activity.index', compact('activities', 'counts', 'upcoming') + $this->flags($request));
    }

    public function show(Request $request, int $id, KramaAccess $krama)
    {
        $activity = $this->access->activity($request->user(), $id);
        $targets = $this->query->table('activity_targets')->where('activity_id', $id)->orderBy('id')->get();
        $participants = $this->access->participants($request->user(), $id)->get(['status']);
        $flags = $this->flags($request);
        $search = mb_substr(trim((string) $request->query('cari', '')), 0, 100);

        return view('activity.show', $flags + [
            'activity' => $activity, 'targets' => $targets, 'summary' => $this->manager->summary($participants),
            'units' => $this->query->table('organizational_units')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'roles' => DB::table('roles')->where('scope', 'TENANT')->where('code', '<>', 'KRAMA')->orderBy('name')->get(['id', 'name']),
            'unitNames' => $this->query->table('organizational_units')->pluck('name', 'id'), 'roleNames' => DB::table('roles')->pluck('name', 'id'),
            'kramaNames' => $this->query->table('krama')->whereIn('id', $targets->pluck('krama_id')->filter()->all() ?: [0])->pluck('name', 'id'),
            'search' => $search, 'people' => $flags['canManage'] && $search !== '' ? $this->query->table('krama')->where('krama.status', 'ACTIVE')->where('name', 'like', '%'.$search.'%')
                ->join('households as h', 'h.id', '=', 'krama.household_id')->where('h.status', 'ACTIVE')->orderBy('name')->limit(20)->get(['krama.id', 'krama.name', 'h.code']) : collect(),
        ]);
    }

    public function settings(Request $request, int $id)
    {
        $this->manager->configure($request->user(), $id, $request->only(['kind', 'visibility', 'attendance_basis', 'allow_representative']));

        return back()->with('success', 'Pengaturan kegiatan disimpan.');
    }

    public function addTarget(Request $request, int $id)
    {
        $this->manager->addTarget($request->user(), $id, $request->only(['target_type', 'unit_id', 'include_descendants', 'role_id', 'krama_id']));

        return redirect('/admin/activities/'.$id)->with('success', 'Target peserta ditambahkan. Perbarui daftar peserta agar berlaku.');
    }

    public function removeTarget(Request $request, int $id, int $target)
    {
        $this->manager->removeTarget($request->user(), $id, $target);

        return back()->with('success', 'Target dihapus. Perbarui daftar peserta agar berlaku.');
    }

    public function generate(Request $request, int $id)
    {
        $result = $this->manager->generate($request->user(), $id);

        return back()->with('success', "Daftar peserta diperbarui: {$result['total']} peserta ({$result['added']} baru, {$result['removed']} dihapus).");
    }

    public function attendance(Request $request, int $id)
    {
        $user = $request->user();
        $activity = $this->access->activity($user, $id);
        $status = $request->query('status');
        $search = mb_substr(trim((string) $request->query('cari', '')), 0, 100);
        $base = $this->access->participants($user, $id);
        $summary = $this->manager->summary((clone $base)->get(['activity_participants.status']));
        $rows = $base->leftJoin('krama as k', 'k.id', '=', 'activity_participants.krama_id')->leftJoin('households as h', 'h.id', '=', 'activity_participants.household_id')
            ->when(array_key_exists((string) $status, ActivityManager::STATUSES), fn ($q) => $q->where('activity_participants.status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('k.name', 'like', '%'.$search.'%')->orWhere('activity_participants.household_code', 'like', '%'.$search.'%')))
            ->select('activity_participants.*', 'k.name as person_name', 'h.head_krama_id')->orderBy('activity_participants.household_code')->orderBy('k.name')->paginate(40)->withQueryString();
        $households = $rows->pluck('household_id')->unique()->all();
        $members = $this->query->table('krama')->whereIn('household_id', $households ?: [0])->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name', 'household_id'])->groupBy('household_id');

        return view('activity.attendance', compact('activity', 'rows', 'summary', 'members', 'status', 'search') + $this->flags($request));
    }

    public function record(Request $request, int $id, int $participant)
    {
        $this->manager->record($request->user(), $id, $participant, $request->only(['status', 'attending_krama_id', 'note']));

        return back()->with('success', 'Presensi dicatat.');
    }

    public function remaining(Request $request, int $id)
    {
        $count = $this->manager->markRemainingAbsent($request->user(), $id);

        return back()->with('success', $count.' peserta yang belum dicatat ditandai tidak hadir.');
    }

    public function verify(Request $request, int $id)
    {
        $count = $this->manager->verify($request->user(), $id);

        return back()->with('success', 'Presensi diverifikasi dan dikunci. '.$count.' KK menjadi calon dedosan untuk diperiksa.');
    }

    public function dedosan(Request $request, int $id)
    {
        $activity = $this->access->activity($request->user(), $id);
        $candidates = $this->query->table('dedosan_candidates')->where('activity_id', $id)->orderBy('household_code')->get();
        $members = $this->query->table('krama')->whereIn('household_id', $candidates->pluck('household_id')->all() ?: [0])->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name', 'household_id'])->groupBy('household_id');
        $absentees = $this->query->table('activity_participants')->where('activity_participants.activity_id', $id)->where('activity_participants.status', 'TIDAK_HADIR')->whereNotNull('activity_participants.krama_id')
            ->join('krama as k', 'k.id', '=', 'activity_participants.krama_id')->get(['activity_participants.household_id', 'k.name'])->groupBy('household_id');
        $types = $this->query->table('billing_types')->where('kind', 'DEDOSAN')->where('is_active', true)->orderBy('name')->get();
        $names = $this->query->table('krama')->whereIn('id', $candidates->pluck('responsible_krama_id')->merge($candidates->pluck('suggested_krama_id'))->filter()->all() ?: [0])->pluck('name', 'id');

        return view('activity.dedosan', compact('activity', 'candidates', 'members', 'absentees', 'types', 'names') + $this->flags($request));
    }

    public function decide(Request $request, int $id, int $candidate, DedosanCandidateManager $manager)
    {
        $manager->decide($request->user(), $id, $candidate, $request->only(['decision', 'responsible_krama_id', 'note']));

        return back()->with('success', 'Keputusan calon dedosan disimpan.');
    }

    public function bulkDecide(Request $request, int $id, DedosanCandidateManager $manager)
    {
        // Baris tanpa keputusan dipilih ("Tidak diubah") tidak dikirim ke manager sama sekali —
        // ini yang membedakan "disertakan dalam pengiriman ini" dari "dibiarkan seperti semula".
        $decisions = collect($request->input('decisions', []))
            ->filter(fn ($row) => ! empty($row['decision'] ?? null))
            ->map(fn ($row, $candidateId) => (is_array($row) ? $row : []) + ['candidate_id' => (int) $candidateId])
            ->values()->all();
        $manager->decideBulk($request->user(), $id, ['decisions' => $decisions]);

        return back()->with('success', 'Keputusan calon dedosan disimpan.');
    }

    public function createEvent(Request $request, int $id, DedosanCandidateManager $manager)
    {
        $event = $manager->createEvent($request->user(), $id, $request->only(['billing_type_id', 'amount', 'due_date']));

        return redirect('/admin/finance/events/'.$event)->with('success', 'Event dedosan dibuat sebagai draf. Periksa dan lanjutkan persetujuan atau penerbitan.');
    }

    public function minutes(Request $request, int $id)
    {
        $activity = $this->access->activity($request->user(), $id);

        return view('activity.minutes', $this->flags($request) + ['activity' => $activity,
            'minutes' => $this->query->table('activity_minutes')->where('activity_id', $id)->first(),
            'decisions' => $this->query->table('activity_decisions')->where('activity_id', $id)->orderBy('sort_order')->get(),
            'attachments' => $this->query->table('activity_attachments')->where('activity_attachments.activity_id', $id)->join('media_files as m', 'm.id', '=', 'activity_attachments.media_id')->get(['activity_attachments.id', 'm.name', 'm.mime', 'm.size']),
            'media' => $this->query->table('media_files')->orderByDesc('id')->limit(100)->get(['id', 'name', 'visibility'])]);
    }

    public function saveMinutes(Request $request, int $id, MinutesManager $manager)
    {
        $manager->save($request->user(), $id, $request->only(['agenda', 'minutes', 'decisions', 'version']));

        return back()->with('success', 'Notulen disimpan.');
    }

    public function attach(Request $request, int $id, MinutesManager $manager)
    {
        $manager->attach($request->user(), $id, $request->only('media_id'));

        return back()->with('success', 'Lampiran ditambahkan.');
    }

    public function detach(Request $request, int $id, int $attachment, MinutesManager $manager)
    {
        $manager->detach($request->user(), $id, $attachment);

        return back()->with('success', 'Lampiran dilepas.');
    }

    public function finalize(Request $request, int $id, MinutesManager $manager)
    {
        $manager->finalize($request->user(), $id);

        return back()->with('success', 'Notulen ditetapkan final.');
    }

    public function attachment(Request $request, int $id, int $attachment)
    {
        $this->access->activity($request->user(), $id);
        $file = $this->query->table('activity_attachments')->where('activity_attachments.activity_id', $id)->where('activity_attachments.id', $attachment)->join('media_files as m', 'm.id', '=', 'activity_attachments.media_id')->first(['m.*']);
        abort_unless($file && Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, 'lampiran-'.$attachment.'.'.pathinfo($file->path, PATHINFO_EXTENSION), ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function portal(Request $request)
    {
        $self = $this->access->self($request->user());
        $rows = collect();
        if ($self) {
            $rows = $this->query->table('activity_participants')->join('activities as a', 'a.id', '=', 'activity_participants.activity_id')
                ->where(fn ($q) => $q->where('activity_participants.krama_id', $self->id)->orWhere(fn ($q) => $q->where('activity_participants.basis', 'HOUSEHOLD')->where('activity_participants.household_id', $self->household_id)))
                ->where('a.status', '<>', 'ARCHIVED')->where('a.attendance_status', '<>', 'NONE')->where('a.starts_at', '>=', now()->subDays(30))
                ->orderBy('a.starts_at')->limit(30)->get(['activity_participants.id as participant_id', 'activity_participants.status', 'activity_participants.source', 'activity_participants.note', 'activity_participants.basis', 'a.id', 'a.title', 'a.starts_at', 'a.ends_at', 'a.location', 'a.kind', 'a.attendance_status']);
        }

        return view('activity.portal', compact('self', 'rows'));
    }

    public function excuse(Request $request, int $id)
    {
        $this->manager->excuse($request->user(), $id, $request->only(['status', 'note']));

        return back()->with('success', 'Pemberitahuan izin terkirim ke pengurus.');
    }
}

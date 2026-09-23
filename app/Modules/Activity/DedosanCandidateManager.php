<?php

namespace App\Modules\Activity;

use App\Models\User;
use App\Modules\Finance\FinanceAccess;
use App\Modules\Finance\FinanceManager;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Presensi → verifikasi → calon dedosan → pilih penanggung jawab → event dedosan (DRAFT) → approval/terbit di modul Keuangan.
 */
final class DedosanCandidateManager
{
    public function __construct(private ActivityAccess $access, private FinanceAccess $finance, private FinanceManager $billing, private TenantQuery $query, private CurrentBanjar $tenant, private Audit $audit) {}

    private function authorize(User $actor): void
    {
        $this->access->authorize($actor, 'activity.view');
        $this->finance->authorize($actor, 'dedosan.issue');
    }

    private function write(User $actor, int $activityId, callable $work): mixed
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $activityId, $work) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            $activity = $this->query->table('activities')->where('id', $activityId)->lockForUpdate()->first();
            abort_unless($activity, 404);
            abort_unless($activity->attendance_status === 'VERIFIED', 409, 'Calon dedosan tersedia setelah presensi diverifikasi.');

            return $work($activity);
        }, 3);
    }

    public function decide(User $actor, int $activityId, int $candidateId, array $input): void
    {
        $data = Validator::make($input, [
            'decision' => ['required', Rule::in(['SELECT', 'DISMISS', 'RESET'])], 'responsible_krama_id' => ['nullable', 'required_if:decision,SELECT', 'integer'],
            'note' => ['nullable', 'required_if:decision,DISMISS', 'string', 'min:5', 'max:500'],
        ], [], ['responsible_krama_id' => 'individu penanggung jawab', 'note' => 'alasan'])->validate();
        $this->write($actor, $activityId, function (object $activity) use ($actor, $candidateId, $data) {
            $candidate = $this->query->table('dedosan_candidates')->where('activity_id', $activity->id)->where('id', $candidateId)->lockForUpdate()->first();
            abort_unless($candidate, 404);
            abort_if($candidate->status === 'BILLED', 409, 'Calon ini sudah masuk event dedosan.');
            $update = ['status' => 'PENDING', 'responsible_krama_id' => null, 'note' => null, 'decided_by' => null, 'decided_at' => null];
            if ($data['decision'] === 'SELECT') {
                $person = (int) $data['responsible_krama_id'];
                // Dedosan hanya dikenakan ke kepala keluarga aktif dari KK yang bersangkutan.
                $valid = $this->query->table('krama')->where('id', $person)->where('status', 'ACTIVE')->exists()
                    && $this->query->table('households')->where('id', $candidate->household_id)->where('status', 'ACTIVE')->where('head_krama_id', $person)->exists();
                if (! $valid) {
                    throw ValidationException::withMessages(['responsible_krama_id' => 'Pilih kepala keluarga aktif dari KK '.$candidate->household_code.'.']);
                }
                $update = ['status' => 'SELECTED', 'responsible_krama_id' => $person, 'note' => $data['note'] ?? null, 'decided_by' => $actor->id, 'decided_at' => now()];
            } elseif ($data['decision'] === 'DISMISS') {
                $update = ['status' => 'DISMISSED', 'responsible_krama_id' => null, 'note' => $data['note'], 'decided_by' => $actor->id, 'decided_at' => now()];
            }
            $this->query->table('dedosan_candidates')->where('id', $candidate->id)->update($update + ['updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'dedosan.candidate_'.strtolower($data['decision']), 'dedosan_candidates', $candidate->id, ['status' => $candidate->status], ['status' => $update['status']]);
        });
    }

    // Keputusan massal (Master Prompt §48): Kesinoman memutuskan beberapa calon sekaligus lewat checkbox,
    // bukan satu per satu. Satu transaksi; bila satu keputusan tidak valid, seluruh batch ditolak.
    public function decideBulk(User $actor, int $activityId, array $input): void
    {
        $data = Validator::make($input, [
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.candidate_id' => ['required', 'integer'],
            'decisions.*.decision' => ['required', Rule::in(['SELECT', 'DISMISS', 'RESET'])],
            'decisions.*.responsible_krama_id' => ['nullable', 'required_if:decisions.*.decision,SELECT', 'integer'],
            'decisions.*.note' => ['nullable', 'required_if:decisions.*.decision,DISMISS', 'string', 'min:5', 'max:500'],
        ], [], ['decisions.*.responsible_krama_id' => 'individu penanggung jawab', 'decisions.*.note' => 'alasan'])->validate();

        $this->write($actor, $activityId, function (object $activity) use ($actor, $data) {
            $errors = [];
            $updates = [];
            foreach ($data['decisions'] as $row) {
                $candidate = $this->query->table('dedosan_candidates')->where('activity_id', $activity->id)->where('id', $row['candidate_id'])->lockForUpdate()->first();
                if (! $candidate) {
                    $errors[] = "Calon #{$row['candidate_id']} tidak ditemukan.";

                    continue;
                }
                if ($candidate->status === 'BILLED') {
                    $errors[] = "Calon KK {$candidate->household_code} sudah masuk event dedosan.";

                    continue;
                }
                if ($row['decision'] === 'SELECT') {
                    $person = (int) $row['responsible_krama_id'];
                    $valid = $this->query->table('krama')->where('id', $person)->where('status', 'ACTIVE')->exists()
                        && $this->query->table('households')->where('id', $candidate->household_id)->where('status', 'ACTIVE')->where('head_krama_id', $person)->exists();
                    if (! $valid) {
                        $errors[] = "Pilih kepala keluarga aktif dari KK {$candidate->household_code}.";

                        continue;
                    }
                    $updates[$candidate->id] = ['status' => 'SELECTED', 'responsible_krama_id' => $person, 'note' => $row['note'] ?? null, 'decided_by' => $actor->id, 'decided_at' => now()];
                } elseif ($row['decision'] === 'DISMISS') {
                    $updates[$candidate->id] = ['status' => 'DISMISSED', 'responsible_krama_id' => null, 'note' => $row['note'], 'decided_by' => $actor->id, 'decided_at' => now()];
                } else {
                    $updates[$candidate->id] = ['status' => 'PENDING', 'responsible_krama_id' => null, 'note' => null, 'decided_by' => null, 'decided_at' => null];
                }
            }
            if ($errors) {
                throw ValidationException::withMessages(['decisions' => $errors]);
            }
            foreach ($updates as $id => $update) {
                $this->query->table('dedosan_candidates')->where('id', $id)->update($update + ['updated_at' => now()]);
                $this->audit->record($this->tenant->id(), $actor->id, 'dedosan.candidate_bulk_'.strtolower($update['status']), 'dedosan_candidates', $id, [], ['status' => $update['status']]);
            }
        });
    }

    public function createEvent(User $actor, int $activityId, array $input): int
    {
        $data = Validator::make($input, ['billing_type_id' => ['required', 'integer'], 'amount' => ['nullable', 'regex:/^[1-9][0-9]{0,11}$/'], 'due_date' => ['nullable', 'date_format:Y-m-d']], [], ['billing_type_id' => 'jenis dedosan'])->validate();

        return $this->write($actor, $activityId, function (object $activity) use ($actor, $data) {
            if ($activity->dedosan_billing_event_id) {
                return (int) $activity->dedosan_billing_event_id;
            }
            $candidates = $this->query->table('dedosan_candidates')->where('activity_id', $activity->id)->get();
            if ($candidates->where('status', 'PENDING')->isNotEmpty()) {
                throw ValidationException::withMessages(['billing_type_id' => 'Putuskan semua calon dedosan terlebih dahulu (pilih penanggung jawab atau bebaskan).']);
            }
            $selected = $candidates->where('status', 'SELECTED');
            if ($selected->isEmpty()) {
                throw ValidationException::withMessages(['billing_type_id' => 'Tidak ada calon yang dipilih untuk dedosan.']);
            }
            $type = $this->query->table('billing_types')->where('id', (int) $data['billing_type_id'])->where('kind', 'DEDOSAN')->where('is_active', true)->first();
            if (! $type) {
                throw ValidationException::withMessages(['billing_type_id' => 'Pilih jenis tagihan dedosan yang aktif.']);
            }
            $date = Carbon::parse($activity->starts_at)->timezone('Asia/Makassar');
            // Reuses the Phase 5 dedosan workflow: event starts as DRAFT and follows Kelian approval policy before issue.
            $eventId = $this->billing->event($actor, ['billing_type_id' => $type->id, 'title' => mb_substr('Dedosan: '.$activity->title, 0, 150), 'reference' => 'DEDOSAN-KEG-'.$activity->id,
                'event_date' => $date->toDateString(), 'amount' => $data['amount'] ?? null, 'due_date' => $data['due_date'] ?? null]);
            foreach ($selected as $candidate) {
                $reason = 'Tidak hadir pada '.$activity->title.' ('.$date->translatedFormat('d F Y').'), KK '.$candidate->household_code.'.'.($candidate->note ? ' Catatan: '.$candidate->note : '');
                $this->billing->subject($actor, $eventId, ['subject_krama_id' => $candidate->responsible_krama_id, 'reason' => mb_substr($reason, 0, 1000)]);
            }
            $this->query->table('dedosan_candidates')->whereIn('id', $selected->pluck('id')->all())->update(['status' => 'BILLED', 'billing_event_id' => $eventId, 'updated_at' => now()]);
            $this->query->table('activities')->where('id', $activity->id)->update(['dedosan_billing_event_id' => $eventId, 'updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'dedosan.event_from_activity', 'activities', $activity->id, [], ['billing_event_id' => $eventId, 'amount' => $selected->count()]);

            return $eventId;
        });
    }
}

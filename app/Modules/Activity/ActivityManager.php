<?php

namespace App\Modules\Activity;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Activity operations on the single `activities` entity: targets → participant snapshot → attendance → verification → dedosan candidates.
 * Every write locks the Banjar guard row first, then the activity row.
 */
final class ActivityManager
{
    public const KINDS = ['GENERAL' => 'Kegiatan umum', 'PARUMAN' => 'Paruman', 'NGAYAH' => 'Ngayah', 'GOTONG_ROYONG' => 'Gotong royong', 'UPACARA' => 'Upacara', 'RAPAT' => 'Rapat'];

    public const TARGETS = ['ALL_HOUSEHOLDS' => 'Semua KK aktif', 'ALL_KRAMA' => 'Semua krama aktif', 'ORGANIZATIONAL_UNIT' => 'Unit (Tempekan/Regu/Maksan)', 'ROLE' => 'Pengurus dengan peran', 'SELECTED_KRAMA' => 'Krama tertentu'];

    public const STATUSES = ['BELUM_DIVERIFIKASI' => 'Belum dicatat', 'HADIR' => 'Hadir', 'IZIN' => 'Izin', 'SAKIT' => 'Sakit', 'TIDAK_HADIR' => 'Tidak hadir'];

    public function __construct(private ActivityAccess $access, private TenantQuery $query, private CurrentBanjar $tenant, private Audit $audit) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function write(User $actor, int $id, string $permission, callable $work): mixed
    {
        $this->access->activity($actor, $id, $permission);

        return DB::transaction(function () use ($actor, $id, $permission, $work) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->firstOrFail();

            return $work($this->access->activity($actor, $id, $permission, true));
        }, 3);
    }

    private function log(User $actor, string $action, int $id, array $new = []): void
    {
        $this->audit->record($this->tenant->id(), $actor->id, $action, 'activities', $id, [], $new);
    }

    public function configure(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in(array_keys(self::KINDS))], 'visibility' => ['required', Rule::in(['PUBLIC', 'INTERNAL'])],
            'attendance_basis' => ['nullable', Rule::in(['HOUSEHOLD', 'PERSON'])], 'allow_representative' => ['required', 'boolean'],
        ], [], ['attendance_basis' => 'basis presensi'])->validate();
        $this->write($actor, $id, 'activity.manage', function (object $activity) use ($actor, $data) {
            abort_if($activity->attendance_status === 'VERIFIED', 409, 'Presensi sudah diverifikasi dan tidak dapat diubah.');
            $basis = $data['attendance_basis'] ?? null;
            $hasParticipants = $this->query->table('activity_participants')->where('activity_id', $activity->id)->exists();
            if ($hasParticipants && $basis !== $activity->attendance_basis) {
                $this->fail('attendance_basis', 'Basis presensi tidak dapat diganti setelah daftar peserta dibuat.');
            }
            $update = ['kind' => $data['kind'], 'visibility' => $data['visibility'], 'attendance_basis' => $basis, 'allow_representative' => (bool) $data['allow_representative'], 'updated_at' => now()];
            if (! $basis) {
                $update['attendance_status'] = 'NONE';
            }
            $this->query->table('activities')->where('id', $activity->id)->update($update);
            $this->log($actor, 'activity.configured', $activity->id, ['status' => $basis ?? 'NONE']);
        });
    }

    public function addTarget(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, [
            'target_type' => ['required', Rule::in(array_keys(self::TARGETS))], 'unit_id' => ['nullable', 'integer'], 'include_descendants' => ['nullable', 'boolean'],
            'role_id' => ['nullable', 'integer'], 'krama_id' => ['nullable', 'integer'],
        ], [], ['target_type' => 'jenis target'])->validate();
        $this->write($actor, $id, 'activity.manage', function (object $activity) use ($actor, $data) {
            abort_if($activity->attendance_status === 'VERIFIED', 409, 'Presensi sudah diverifikasi.');
            $type = $data['target_type'];
            $row = ['target_type' => $type, 'unit_id' => null, 'role_id' => null, 'krama_id' => null, 'include_descendants' => false];
            if ($type === 'ORGANIZATIONAL_UNIT') {
                if (! $this->query->table('organizational_units')->where('id', (int) ($data['unit_id'] ?? 0))->where('is_active', true)->exists()) {
                    $this->fail('unit_id', 'Pilih unit aktif milik Banjar ini.');
                }
                $row['unit_id'] = (int) $data['unit_id'];
                $row['include_descendants'] = (bool) ($data['include_descendants'] ?? false);
            } elseif ($type === 'ROLE') {
                if (! DB::table('roles')->where('id', (int) ($data['role_id'] ?? 0))->where('scope', 'TENANT')->where('code', '<>', 'KRAMA')->exists()) {
                    $this->fail('role_id', 'Pilih peran pengurus Banjar.');
                }
                $row['role_id'] = (int) $data['role_id'];
            } elseif ($type === 'SELECTED_KRAMA') {
                if (! $this->activePeople()->where('krama.id', (int) ($data['krama_id'] ?? 0))->exists()) {
                    $this->fail('krama_id', 'Pilih krama aktif dari KK aktif.');
                }
                $row['krama_id'] = (int) $data['krama_id'];
            }
            $key = $type.':'.($row['unit_id'] ?? $row['role_id'] ?? $row['krama_id'] ?? 'all').($row['include_descendants'] ? ':d' : '');
            $inserted = DB::table('activity_targets')->insertOrIgnore($row + ['banjar_id' => $this->tenant->id(), 'activity_id' => $activity->id, 'target_key' => $key, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($inserted) {
                $this->log($actor, 'activity.target_added', $activity->id, ['code' => $key]);
            }
        });
    }

    public function removeTarget(User $actor, int $id, int $targetId): void
    {
        $this->write($actor, $id, 'activity.manage', function (object $activity) use ($actor, $targetId) {
            abort_if($activity->attendance_status === 'VERIFIED', 409, 'Presensi sudah diverifikasi.');
            $target = $this->query->table('activity_targets')->where('activity_id', $activity->id)->where('id', $targetId)->first();
            abort_unless($target, 404);
            $this->query->table('activity_targets')->where('id', $target->id)->delete();
            $this->log($actor, 'activity.target_removed', $activity->id, ['code' => $target->target_key]);
        });
    }

    private function activePeople()
    {
        return $this->query->table('krama')->where('krama.status', 'ACTIVE')
            ->whereIn('krama.household_id', $this->query->table('households')->where('status', 'ACTIVE')->select('id'));
    }

    private function unitBranch(object $target): array
    {
        $units = $this->query->table('organizational_units')->where('is_active', true)->get(['id', 'parent_id']);
        if (! $units->contains('id', $target->unit_id)) {
            return [];
        }
        $branch = [(int) $target->unit_id];
        if ($target->include_descendants) {
            do {
                $count = count($branch);
                foreach ($units as $unit) {
                    if (in_array((int) $unit->parent_id, $branch, true) && ! in_array((int) $unit->id, $branch, true)) {
                        $branch[] = (int) $unit->id;
                    }
                }
            } while ($count !== count($branch));
        }

        return $branch;
    }

    /**
     * Union of all targets, deduplicated. Returns krama ids (PERSON) or household ids (HOUSEHOLD).
     *
     * @return array<int, int>
     */
    public function resolve(object $activity): array
    {
        $person = $activity->attendance_basis === 'PERSON';
        $ids = collect();
        foreach ($this->query->table('activity_targets')->where('activity_id', $activity->id)->get() as $target) {
            $people = match ($target->target_type) {
                'ALL_HOUSEHOLDS' => null,
                'ALL_KRAMA' => $this->activePeople(),
                'ORGANIZATIONAL_UNIT' => (function () use ($target) {
                    $branch = $this->unitBranch($target);

                    return $this->activePeople()->where(fn ($q) => $q
                        ->whereIn('krama.household_id', $this->query->table('household_unit_memberships')->whereIn('unit_id', $branch ?: [0])->select('household_id'))
                        ->orWhereIn('krama.id', $this->query->table('krama_unit_memberships')->whereIn('unit_id', $branch ?: [0])->select('krama_id')));
                })(),
                'ROLE' => $this->activePeople()->whereIn('krama.user_id', DB::table('banjar_user_memberships as m')->where('m.banjar_id', $this->tenant->id())->where('m.status', 'ACTIVE')
                    ->join('tenant_role_assignments as a', fn ($j) => $j->on('a.membership_id', '=', 'm.id')->on('a.banjar_id', '=', 'm.banjar_id'))
                    ->where('a.role_id', $target->role_id)->select('m.user_id')),
                'SELECTED_KRAMA' => $this->activePeople()->where('krama.id', $target->krama_id),
            };
            if ($people === null) {
                // All active households: as persons this means every active member.
                $ids = $ids->merge($person ? $this->activePeople()->pluck('krama.id') : $this->query->table('households')->where('status', 'ACTIVE')->pluck('id'));
            } elseif ($target->target_type === 'ORGANIZATIONAL_UNIT' && ! $person) {
                // Household basis: households in the unit plus households of individual unit members.
                $branch = $this->unitBranch($target) ?: [0];
                $ids = $ids->merge($this->query->table('households')->where('status', 'ACTIVE')->where(fn ($q) => $q
                    ->whereIn('id', $this->query->table('household_unit_memberships')->whereIn('unit_id', $branch)->select('household_id'))
                    ->orWhereIn('id', $this->activePeople()->whereIn('krama.id', $this->query->table('krama_unit_memberships')->whereIn('unit_id', $branch)->select('krama_id'))->select('krama.household_id')))->pluck('id'));
            } else {
                $ids = $ids->merge($person ? $people->pluck('krama.id') : $people->distinct()->pluck('krama.household_id'));
            }
        }

        return $ids->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
    }

    public function generate(User $actor, int $id): array
    {
        return $this->write($actor, $id, 'activity.manage', function (object $activity) use ($actor) {
            abort_if($activity->attendance_status === 'VERIFIED', 409, 'Presensi sudah diverifikasi.');
            if (! $activity->attendance_basis) {
                $this->fail('attendance_basis', 'Pilih basis presensi terlebih dahulu.');
            }
            if (! $this->query->table('activity_targets')->where('activity_id', $activity->id)->exists()) {
                $this->fail('target_type', 'Tambahkan minimal satu target peserta.');
            }
            $person = $activity->attendance_basis === 'PERSON';
            $wanted = $this->resolve($activity);
            $column = $person ? 'krama_id' : 'household_id';
            $existing = $this->query->table('activity_participants')->where('activity_id', $activity->id)->get()->keyBy($column);
            $removed = $existing->keys()->map(fn ($v) => (int) $v)->diff($wanted);
            $recorded = $removed->filter(fn ($key) => $existing[$key]->status !== 'BELUM_DIVERIFIKASI');
            if ($recorded->isNotEmpty()) {
                $this->fail('target_type', $recorded->count().' peserta yang akan dihapus sudah memiliki catatan presensi. Kembalikan target atau kosongkan catatannya terlebih dahulu.');
            }
            if ($removed->isNotEmpty()) {
                $this->query->table('activity_participants')->where('activity_id', $activity->id)->whereIn($column, $removed->all())->delete();
            }
            $new = collect($wanted)->diff($existing->keys()->map(fn ($v) => (int) $v));
            $households = $person
                ? $this->query->table('krama')->whereIn('krama.id', $new->all() ?: [0])->join('households as h', 'h.id', '=', 'krama.household_id')->get(['krama.id', 'h.id as household_id', 'h.code'])->keyBy('id')
                : $this->query->table('households')->whereIn('id', $new->all() ?: [0])->get(['id', 'id as household_id', 'code'])->keyBy('id');
            foreach ($new->chunk(500) as $chunk) {
                $rows = [];
                foreach ($chunk as $key) {
                    $h = $households[$key];
                    $rows[] = ['banjar_id' => $this->tenant->id(), 'activity_id' => $activity->id, 'basis' => $activity->attendance_basis, 'household_id' => $h->household_id,
                        'household_code' => $h->code, 'krama_id' => $person ? $key : null, 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('activity_participants')->insertOrIgnore($rows);
            }
            $this->query->table('activities')->where('id', $activity->id)->update(['attendance_status' => 'DRAFT', 'participants_generated_at' => now(), 'updated_at' => now()]);
            $result = ['added' => $new->count(), 'removed' => $removed->count(), 'total' => count($wanted)];
            $this->log($actor, 'activity.participants_generated', $activity->id, ['amount' => count($wanted)]);

            return $result;
        });
    }

    public function record(User $actor, int $id, int $participantId, array $input): void
    {
        $data = Validator::make($input, [
            'status' => ['required', Rule::in(array_keys(self::STATUSES))], 'attending_krama_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:500'],
        ], [], ['attending_krama_id' => 'krama yang hadir'])->validate();
        $this->write($actor, $id, 'attendance.record', function (object $activity) use ($actor, $participantId, $data) {
            abort_unless($activity->attendance_status === 'DRAFT', 409, $activity->attendance_status === 'VERIFIED' ? 'Presensi sudah diverifikasi dan dikunci.' : 'Daftar peserta belum dibuat.');
            $participant = $this->access->participants($actor, $activity->id, 'attendance.record')->where('activity_participants.id', $participantId)->lockForUpdate()->first();
            abort_unless($participant, 404);
            $this->apply($actor, $activity, $participant, $data, 'STAFF');
        });
    }

    private function apply(User $actor, object $activity, object $participant, array $data, string $source): void
    {
        $attending = null;
        if ($data['status'] === 'HADIR' && $participant->basis === 'HOUSEHOLD') {
            $household = $this->query->table('households')->where('id', $participant->household_id)->first();
            $attending = (int) ($data['attending_krama_id'] ?? 0) ?: ($household->head_krama_id ? (int) $household->head_krama_id : null);
            if ($attending && ! $this->activePeople()->where('krama.id', $attending)->where('krama.household_id', $participant->household_id)->exists()) {
                $this->fail('attending_krama_id', 'Yang hadir harus anggota aktif KK '.$participant->household_code.'.');
            }
            if (! $activity->allow_representative && $attending !== ($household->head_krama_id ? (int) $household->head_krama_id : -1)) {
                $this->fail('attending_krama_id', 'Kegiatan ini tidak mengizinkan perwakilan; kepala keluarga harus hadir sendiri.');
            }
        } elseif (! empty($data['attending_krama_id']) && $participant->basis === 'PERSON') {
            $this->fail('attending_krama_id', 'Presensi individu tidak dapat diwakilkan.');
        }
        $reset = $data['status'] === 'BELUM_DIVERIFIKASI';
        $this->query->table('activity_participants')->where('id', $participant->id)->update(['status' => $data['status'], 'attending_krama_id' => $attending,
            'note' => $reset ? null : ($data['note'] ?? null), 'source' => $reset ? null : $source, 'recorded_by' => $reset ? null : $actor->id, 'recorded_at' => $reset ? null : now(), 'updated_at' => now()]);
        $this->audit->record($this->tenant->id(), $actor->id, 'attendance.recorded', 'activity_participants', $participant->id, ['status' => $participant->status], ['status' => $data['status']]);
    }

    public function markRemainingAbsent(User $actor, int $id): int
    {
        return $this->write($actor, $id, 'attendance.record', function (object $activity) use ($actor) {
            abort_unless($activity->attendance_status === 'DRAFT', 409);
            abort_if(Carbon::parse($activity->starts_at)->isFuture(), 409, 'Kegiatan belum dimulai.');
            $ids = $this->access->participants($actor, $activity->id, 'attendance.record')->where('activity_participants.status', 'BELUM_DIVERIFIKASI')->pluck('activity_participants.id');
            $count = $ids->isEmpty() ? 0 : $this->query->table('activity_participants')->whereIn('id', $ids->all())->where('status', 'BELUM_DIVERIFIKASI')
                ->update(['status' => 'TIDAK_HADIR', 'source' => 'STAFF', 'recorded_by' => $actor->id, 'recorded_at' => now(), 'updated_at' => now()]);
            $this->log($actor, 'attendance.remaining_absent', $activity->id, ['amount' => $count]);

            return $count;
        });
    }

    // Krama self-service: izin/sakit for their own slot before the activity ends; never overrides a staff record.
    public function excuse(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, ['status' => ['required', Rule::in(['IZIN', 'SAKIT'])], 'note' => ['required', 'string', 'min:5', 'max:500']], [], ['note' => 'alasan'])->validate();
        $this->access->authorize($actor, 'activity.self');
        DB::transaction(function () use ($actor, $id, $data) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->firstOrFail();
            $self = $this->access->self($actor);
            abort_unless($self, 403, 'Akun belum terhubung ke data krama aktif.');
            $activity = $this->query->table('activities')->where('id', $id)->where('status', '<>', 'ARCHIVED')->lockForUpdate()->first();
            abort_unless($activity && $activity->attendance_status === 'DRAFT', 404);
            $end = Carbon::parse($activity->ends_at ?? Carbon::parse($activity->starts_at)->addHours(6));
            abort_if($end->isPast(), 409, 'Kegiatan sudah selesai.');
            $participant = $this->query->table('activity_participants')->where('activity_id', $id)
                ->where(fn ($q) => $q->where('krama_id', $self->id)->orWhere(fn ($q) => $q->where('basis', 'HOUSEHOLD')->where('household_id', $self->household_id)))->lockForUpdate()->first();
            abort_unless($participant, 404);
            abort_if($participant->source === 'STAFF', 409, 'Presensi Anda sudah dicatat pengurus.');
            $this->apply($actor, $activity, $participant, $data + ['attending_krama_id' => null], 'SELF');
        }, 3);
    }

    public function verify(User $actor, int $id): int
    {
        return $this->write($actor, $id, 'attendance.verify', function (object $activity) use ($actor) {
            if ($activity->attendance_status === 'VERIFIED') {
                return $this->query->table('dedosan_candidates')->where('activity_id', $activity->id)->count();
            }
            abort_unless($activity->attendance_status === 'DRAFT', 409, 'Daftar peserta belum dibuat.');
            abort_if(Carbon::parse($activity->starts_at)->isFuture(), 409, 'Presensi hanya dapat diverifikasi setelah kegiatan dimulai.');
            // Verification is Banjar-wide: the verifier must see every slot, not a unit subset.
            $this->access->authorize($actor, 'attendance.verify');
            $pending = $this->query->table('activity_participants')->where('activity_id', $activity->id)->where('status', 'BELUM_DIVERIFIKASI')->count();
            if ($pending) {
                $this->fail('verify', $pending.' peserta belum dicatat. Lengkapi presensi sebelum verifikasi.');
            }
            $absent = $this->query->table('activity_participants')->where('activity_id', $activity->id)->where('status', 'TIDAK_HADIR')->orderBy('id')->get()->groupBy('household_id');
            $heads = $this->query->table('households')->whereIn('id', $absent->keys()->all() ?: [0])->pluck('head_krama_id', 'id');
            $candidateCount = 0;
            foreach ($absent as $household => $rows) {
                $head = $heads[$household] ?? null;
                if (! $head) {
                    // Dedosan hanya dikenakan ke kepala keluarga; KK tanpa kepala keluarga dikecualikan
                    // sampai kepala keluarganya ditetapkan di halaman KK.
                    continue;
                }
                $first = $rows->first();
                // Absence creates a candidate for review, never a bill (Rule 11). One candidate per KK (Rule 10).
                // The responsible party is always the head of household, regardless of which member(s) were absent.
                DB::table('dedosan_candidates')->insertOrIgnore(['banjar_id' => $this->tenant->id(), 'activity_id' => $activity->id, 'household_id' => $household, 'household_code' => $first->household_code,
                    'absent_count' => $rows->count(), 'suggested_krama_id' => (int) $head, 'created_at' => now(), 'updated_at' => now()]);
                $candidateCount++;
            }
            $this->query->table('activities')->where('id', $activity->id)->update(['attendance_status' => 'VERIFIED', 'verified_by' => $actor->id, 'verified_at' => now(), 'updated_at' => now()]);
            $this->log($actor, 'attendance.verified', $activity->id, ['status' => 'VERIFIED', 'amount' => $candidateCount]);

            return $candidateCount;
        });
    }

    /**
     * @return array<string, int>
     */
    public function summary(Collection $participants): array
    {
        return collect(array_keys(self::STATUSES))->mapWithKeys(fn ($s) => [$s => $participants->where('status', $s)->count()])->all() + ['TOTAL' => $participants->count()];
    }
}

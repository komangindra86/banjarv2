<?php

namespace App\Modules\Activity;

use App\Models\User;
use App\Modules\Website\MediaManager;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// Paruman/rapat notes on the same activity: agenda, notulen, keputusan, lampiran. Final minutes are immutable.
final class MinutesManager
{
    public function __construct(private ActivityAccess $access, private TenantQuery $query, private CurrentBanjar $tenant, private Audit $audit, private MediaManager $media) {}

    private function write(User $actor, int $id, callable $work): void
    {
        $this->access->activity($actor, $id, 'activity.manage');
        DB::transaction(function () use ($actor, $id, $work) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->firstOrFail();
            $activity = $this->access->activity($actor, $id, 'activity.manage', true);
            $minutes = $this->query->table('activity_minutes')->where('activity_id', $id)->lockForUpdate()->first();
            abort_if($minutes && $minutes->status === 'FINAL', 409, 'Notulen sudah final dan tidak dapat diubah.');
            $work($activity, $minutes);
        }, 3);
    }

    public function save(User $actor, int $id, array $input): void
    {
        $input['decisions'] = collect(preg_split('/\R/', (string) ($input['decisions'] ?? '')))->map(fn ($l) => trim($l))->filter()->values()->all();
        $data = Validator::make($input, ['agenda' => ['nullable', 'string', 'max:5000'], 'minutes' => ['nullable', 'string', 'max:100000'], 'version' => ['nullable', 'integer'],
            'decisions' => ['array', 'max:50'], 'decisions.*' => ['string', 'max:2000']], [], ['decisions.*' => 'keputusan'])->validate();
        $this->write($actor, $id, function (object $activity, ?object $minutes) use ($actor, $data) {
            if ($minutes && (int) ($data['version'] ?? 0) !== (int) $minutes->version) {
                throw ValidationException::withMessages(['minutes' => 'Notulen sudah diubah pengurus lain. Muat ulang halaman sebelum menyimpan.']);
            }
            $row = ['agenda' => $data['agenda'] ?? null, 'minutes' => $data['minutes'] ?? null, 'updated_by' => $actor->id, 'updated_at' => now()];
            if ($minutes) {
                $this->query->table('activity_minutes')->where('id', $minutes->id)->update($row + ['version' => $minutes->version + 1]);
            } else {
                DB::table('activity_minutes')->insert($row + ['banjar_id' => $this->tenant->id(), 'activity_id' => $activity->id, 'created_at' => now()]);
            }
            $this->query->table('activity_decisions')->where('activity_id', $activity->id)->delete();
            foreach ($data['decisions'] as $i => $body) {
                DB::table('activity_decisions')->insert(['banjar_id' => $this->tenant->id(), 'activity_id' => $activity->id, 'sort_order' => $i + 1, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record($this->tenant->id(), $actor->id, 'minutes.saved', 'activities', $activity->id, [], ['amount' => count($data['decisions'])]);
        });
    }

    // §58: lampiran dipilih langsung di halaman notulen (bisa beberapa sekaligus), disimpan Privat karena hanya
    // untuk pengurus. Izin dan status final diperiksa sebelum berkas disimpan.
    public function attach(User $actor, int $id, mixed $files): int
    {
        $this->access->activity($actor, $id, 'activity.manage');
        abort_if($this->query->table('activity_minutes')->where('activity_id', $id)->value('status') === 'FINAL', 409, 'Notulen sudah final dan tidak dapat diubah.');
        $media = $this->media->storeMany($actor, $files, 'files', true, 'PRIVATE');
        if (! $media) {
            throw ValidationException::withMessages(['files' => 'Pilih minimal satu foto atau berkas PDF.']);
        }
        $this->write($actor, $id, function (object $activity) use ($actor, $media) {
            foreach ($media as $mediaId) {
                DB::table('activity_attachments')->insertOrIgnore(['banjar_id' => $this->tenant->id(), 'activity_id' => $activity->id, 'media_id' => $mediaId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record($this->tenant->id(), $actor->id, 'minutes.attachment_added', 'activities', $activity->id, [], ['amount' => count($media)]);
        });

        return count($media);
    }

    public function detach(User $actor, int $id, int $attachmentId): void
    {
        $this->write($actor, $id, function (object $activity) use ($actor, $attachmentId) {
            abort_unless($this->query->table('activity_attachments')->where('activity_id', $activity->id)->where('id', $attachmentId)->delete(), 404);
            $this->audit->record($this->tenant->id(), $actor->id, 'minutes.attachment_removed', 'activities', $activity->id);
        });
    }

    public function finalize(User $actor, int $id): void
    {
        $this->write($actor, $id, function (object $activity, ?object $minutes) use ($actor) {
            if (! $minutes || trim((string) $minutes->minutes) === '') {
                throw ValidationException::withMessages(['minutes' => 'Isi notulen sebelum ditetapkan final.']);
            }
            $this->query->table('activity_minutes')->where('id', $minutes->id)->update(['status' => 'FINAL', 'finalized_by' => $actor->id, 'finalized_at' => now(), 'updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'minutes.finalized', 'activities', $activity->id, [], ['status' => 'FINAL']);
        });
    }
}

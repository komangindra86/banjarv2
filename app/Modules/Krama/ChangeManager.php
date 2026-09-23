<?php

namespace App\Modules\Krama;

use App\Models\User;
use App\Modules\Notification\NotificationManager;
use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ChangeManager
{
    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private KramaAccess $scope, private Access $access, private KramaManager $manager, private Audit $audit, private NotificationManager $notifications) {}

    public function target(User $actor, int $id): object
    {
        if ($this->access->tenant($actor, 'krama.manage')) {
            $row = $this->scope->people($actor, 'krama.manage')->where('id', $id)->first();
        } else {
            $this->scope->authorize($actor, 'krama.self');
            $row = $this->query->table('krama')->where('user_id', $actor->id)->where('id', $id)->first();
        }
        abort_unless($row, 404);

        return $row;
    }

    public function submit(User $actor, int $id, array $input): int
    {
        $this->target($actor, $id);

        return DB::transaction(function () use ($actor, $id, $input) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->first();
            $row = $this->target($actor, $id);
            $data = Validator::make($input, [
                'field' => ['required', Rule::in(array_keys(KramaManager::FIELDS))],
                'reason' => ['required', 'string', 'max:1000'], 'version' => ['required', 'integer'],
            ])->validate();
            if ((int) $data['version'] !== $row->version) {
                throw ValidationException::withMessages(['version' => 'Data sudah berubah. Muat ulang sebelum mengajukan.']);
            }
            $field = $data['field'];
            // A self-service applicant cannot set operational status without review; all fields follow the same workflow.
            $value = Validator::make([$field => $input['value'] ?? null], [$field => $this->manager->personRules()[$field]])->validate()[$field] ?? null;
            // "nullable" lets an empty <select>/<input> pass through as '' rather than null; normalize before storing
            // so a cleared date/enum field never reaches the database as an invalid empty string.
            if ($value === '') {
                $value = null;
            }
            $old = $field === 'nik' && $row->nik ? Crypt::decryptString($row->nik) : $row->$field;
            if ($old === $value) {
                throw ValidationException::withMessages(['value' => 'Nilai baru masih sama dengan data saat ini.']);
            }
            $request = DB::table('krama_change_requests')->insertGetId([
                'banjar_id' => $this->tenant->id(), 'krama_id' => $id, 'base_version' => $row->version,
                'requires_approval' => (bool) $this->query->table('banjar_settings')->value('krama_changes_require_approval'),
                'requester_id' => $actor->id, 'requester_at' => now(), 'reason' => Crypt::encryptString($data['reason']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('krama_change_items')->insert(['banjar_id' => $this->tenant->id(), 'request_id' => $request, 'field' => $field, 'old_value' => Crypt::encryptString(json_encode($old)), 'new_value' => Crypt::encryptString(json_encode($value)), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'change.submitted', 'krama_change', $request);
            $this->notifications->notifyPermission($this->tenant->id(), 'change.verify', 'change.submitted', 'Pengajuan perubahan data', 'Ada pengajuan perubahan data krama menunggu verifikasi.', '/admin/krama/changes');

            return $request;
        });
    }

    public function visible(User $actor): Builder
    {
        app(ModuleGate::class)->authorize('KRAMA');
        $this->access->requireTenant($actor, 'dashboard.view');

        return $this->query->table('krama_change_requests')->where(function ($q) use ($actor) {
            $q->where('requester_id', $actor->id);
            foreach (['change.verify', 'change.approve'] as $permission) {
                if ($this->access->tenant($actor, $permission)) {
                    $q->orWhereIn('krama_id', $this->scope->people($actor, $permission)->select('krama.id'));
                }
            }
        });
    }

    public function review(User $actor, int $id, array $input): string
    {
        $data = Validator::make($input, ['action' => ['required', Rule::in(['verify', 'approve', 'reject'])], 'review_note' => ['nullable', 'string', 'max:1000', 'required_if:action,reject']])->validate();

        return DB::transaction(function () use ($actor, $id, $data) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->first();
            $request = $this->query->table('krama_change_requests')->where('id', $id)->first();
            abort_unless($request, 404);
            $permission = $data['action'] === 'approve' || ($data['action'] === 'reject' && $request->status === 'VERIFIED') ? 'change.approve' : 'change.verify';
            $person = $this->scope->people($actor, $permission)->where('id', $request->krama_id)->first();
            abort_unless($person, 404);
            abort_if($request->requester_id === $actor->id || ($permission === 'change.approve' && $request->verifier_id === $actor->id), 403, 'Pengaju, verifier, dan approver harus berbeda.');
            $expected = $permission === 'change.approve' ? 'VERIFIED' : 'SUBMITTED';
            if ($request->status !== $expected) {
                throw ValidationException::withMessages(['action' => 'Pengajuan ini sudah diproses. Muat ulang halaman.']);
            }
            $update = ['updated_at' => now(), 'review_note' => empty($data['review_note']) ? null : Crypt::encryptString($data['review_note'])];
            $stage = $permission === 'change.approve' ? 'approver' : 'verifier';
            $update[$stage.'_id'] = $actor->id;
            $update[$stage.'_at'] = now();
            if ($data['action'] === 'reject') {
                $status = 'REJECTED';
            } elseif ($request->base_version !== $person->version) {
                $status = 'CONFLICT';
            } elseif ($data['action'] === 'verify' && $request->requires_approval) {
                $status = 'VERIFIED';
            } else {
                $changes = [];
                foreach ($this->query->table('krama_change_items')->where('request_id', $id)->get() as $item) {
                    abort_unless(array_key_exists($item->field, KramaManager::FIELDS), 409);
                    $value = json_decode(Crypt::decryptString($item->new_value), true, 512, JSON_THROW_ON_ERROR);
                    $changes[$item->field] = $item->field === 'nik' && $value ? Crypt::encryptString($value) : $value;
                }
                if (isset($changes['status']) && $changes['status'] !== 'ACTIVE' && $this->query->table('households')->where('head_krama_id', $person->id)->exists()) {
                    throw ValidationException::withMessages(['action' => 'Ganti atau kosongkan kepala keluarga dahulu, lalu tinjau pengajuan kembali.']);
                }
                $this->query->table('krama')->where('id', $person->id)->update($changes + ['version' => $person->version + 1, 'updated_at' => now()]);
                $status = 'APPLIED';
            }
            $this->query->table('krama_change_requests')->where('id', $id)->update($update + ['status' => $status]);
            $this->audit->record($this->tenant->id(), $actor->id, 'change.'.strtolower($status), 'krama_change', $id, [], ['status' => $status]);
            if ($status === 'VERIFIED') {
                $this->notifications->notifyPermission($this->tenant->id(), 'change.approve', 'change.approval_pending', 'Perubahan data menunggu persetujuan', 'Ada pengajuan perubahan data menunggu persetujuan Kelian.', '/admin/krama/changes');
            }

            return $status;
        });
    }
}

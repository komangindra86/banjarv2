<?php

namespace App\Platform\Identity;

use App\Models\User;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use App\Platform\Support\RequestMemo;

final class MembershipManager
{
    public function __construct(private Access $access, private Audit $audit) {}

    public function create(User $actor, int $banjarId, array $input): void
    {
        $this->access->requirePlatform($actor);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('scope', 'TENANT')],
        ])->validate();
        DB::transaction(function () use ($actor, $banjarId, $data, $input) {
            abort_unless(DB::table('banjars')->where('id', $banjarId)->lockForUpdate()->first(), 404);
            $scope = $this->scope($banjarId, $input);
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $membership = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $banjarId, 'user_id' => $user->id, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('tenant_role_assignments')->insert(['banjar_id' => $banjarId, 'membership_id' => $membership, 'role_id' => $data['role_id']] + $scope);
            $this->audit->record($banjarId, $actor->id, 'membership.created', 'membership', $membership, [], ['user_id' => $user->id, 'role_id' => $data['role_id'], 'status' => 'ACTIVE'] + $scope);
            RequestMemo::flush();
        });
    }

    public function update(User $actor, int $banjarId, int $membershipId, array $input): void
    {
        $this->access->requirePlatform($actor);
        $data = Validator::make($input, ['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])], 'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('scope', 'TENANT')]])->validate();
        DB::transaction(function () use ($actor, $banjarId, $membershipId, $data, $input) {
            abort_unless(DB::table('banjars')->where('id', $banjarId)->lockForUpdate()->first(), 404);
            $member = DB::table('banjar_user_memberships')->where('banjar_id', $banjarId)->where('id', $membershipId)->first();
            abort_unless($member, 404);
            $assignment = DB::table('tenant_role_assignments')->where('banjar_id', $banjarId)->where('membership_id', $membershipId)->first();
            $scope = $this->scope($banjarId, $input + (array) $assignment);
            $oldRole = DB::table('tenant_role_assignments')->where('banjar_id', $banjarId)->where('membership_id', $membershipId)->value('role_id');
            DB::table('banjar_user_memberships')->where('banjar_id', $banjarId)->where('id', $membershipId)->update(['status' => $data['status'], 'updated_at' => now()]);
            DB::table('tenant_role_assignments')->where('banjar_id', $banjarId)->where('membership_id', $membershipId)->delete();
            DB::table('tenant_role_assignments')->insert(['banjar_id' => $banjarId, 'membership_id' => $membershipId, 'role_id' => $data['role_id']] + $scope);
            $hasAdmin = DB::table('banjar_user_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->join('tenant_role_assignments as a', 'a.membership_id', '=', 'm.id')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('m.banjar_id', $banjarId)->where('m.status', 'ACTIVE')->where('u.status', 'ACTIVE')->where('r.code', 'ADMIN_BANJAR')->where('a.scope_type', 'BANJAR')->exists();
            if (! $hasAdmin) {
                throw ValidationException::withMessages(['status' => 'Sisakan minimal satu Admin Banjar aktif.']);
            }
            $this->audit->record($banjarId, $actor->id, 'membership.updated', 'membership', $membershipId, ['status' => $member->status, 'role_id' => $oldRole] + (array) $assignment, $data + $scope);
            RequestMemo::flush();
        });
    }

    private function scope(int $banjarId, array $input): array
    {
        $data = Validator::make($input, [
            'scope_type' => ['sometimes', Rule::in(['BANJAR', 'ORGANIZATIONAL_UNIT'])],
            'unit_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->where('banjar_id', $banjarId)->where('is_active', true)],
            'include_descendants' => ['sometimes', 'boolean'],
        ])->validate();
        $data['scope_type'] ??= 'BANJAR';
        if ($data['scope_type'] === 'BANJAR') {
            return ['scope_type' => 'BANJAR', 'unit_id' => null, 'include_descendants' => false];
        }
        if (empty($data['unit_id'])) {
            throw ValidationException::withMessages(['unit_id' => 'Pilih unit untuk akses terbatas.']);
        }
        $role = DB::table('roles')->where('id', $input['role_id'])->value('code');
        if (! in_array($role, ['KESINOMAN', 'KELIAN'], true)) {
            throw ValidationException::withMessages(['scope_type' => 'Akses unit tersedia untuk Kesinoman dan Kelian.']);
        }

        return $data + ['include_descendants' => false];
    }
}

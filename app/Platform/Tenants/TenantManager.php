<?php

namespace App\Platform\Tenants;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use App\Platform\Support\RequestMemo;

final class TenantManager
{
    public function __construct(private Access $access, private Audit $audit) {}

    public function create(User $actor, array $input): int
    {
        $this->access->requirePlatform($actor);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:banjars,slug', Rule::notIn(['admin', 'www', 'api', 'mail', 'webhooks'])],
            'admin_name' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ])->validate();
        $domain = $data['slug'].'.'.config('platform.tenant_base_domain');
        if (in_array($domain, [config('platform.host'), config('platform.portal_host')], true) || strlen($domain) > 253 || DB::table('tenant_domains')->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['slug' => 'Subdomain sudah dipakai atau tidak valid.']);
        }

        return DB::transaction(function () use ($data, $domain, $actor) {
            $now = now();
            $id = DB::table('banjars')->insertGetId(['name' => $data['name'], 'slug' => $data['slug'], 'status' => 'DRAFT', 'created_at' => $now, 'updated_at' => $now]);
            // Only platform-owned wildcard domains. Custom domains need future DNS/TLS verification.
            $domainId = DB::table('tenant_domains')->insertGetId(['banjar_id' => $id, 'domain' => $domain, 'verification_status' => 'VERIFIED', 'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('banjars')->where('id', $id)->update(['primary_domain_id' => $domainId, 'status' => 'ACTIVE']);
            DB::table('banjar_settings')->insert(['banjar_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
            foreach (array_keys(config('platform.modules')) as $code) {
                DB::table('banjar_modules')->insert(['banjar_id' => $id, 'code' => $code, 'status' => match ($code) {
                    'WEBSITE' => 'ACTIVE', 'ACTIVITY' => 'BASIC', default => 'INACTIVE'
                }, 'created_at' => $now, 'updated_at' => $now]);
            }
            $user = User::create(['name' => $data['admin_name'], 'email' => strtolower($data['admin_email']), 'password' => Hash::make($data['admin_password'])]);
            $membership = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $id, 'user_id' => $user->id, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('tenant_role_assignments')->insert(['banjar_id' => $id, 'membership_id' => $membership, 'role_id' => DB::table('roles')->where('code', 'ADMIN_BANJAR')->soleValue('id')]);
            $this->audit->record($id, $actor->id, 'tenant.created', 'banjar', $id, [], ['name' => $data['name'], 'slug' => $data['slug'], 'domain' => $domain, 'status' => 'ACTIVE']);

            return $id;
        });
    }

    public function status(User $actor, int $id, array $input): void
    {
        $this->access->requirePlatform($actor);
        $data = Validator::make($input, ['status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED', 'ARCHIVED'])]])->validate();
        DB::transaction(function () use ($actor, $id, $data) {
            $banjar = DB::table('banjars')->where('id', $id)->lockForUpdate()->first();
            abort_unless($banjar, 404);
            if ($data['status'] === 'ACTIVE') {
                $domainReady = DB::table('tenant_domains')->where('banjar_id', $id)->where('id', $banjar->primary_domain_id)->where('verification_status', 'VERIFIED')->whereNotNull('verified_at')->exists();
                $adminReady = DB::table('banjar_user_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('tenant_role_assignments as a', 'a.membership_id', '=', 'm.id')->join('roles as r', 'r.id', '=', 'a.role_id')->where('m.banjar_id', $id)->where('m.status', 'ACTIVE')->where('u.status', 'ACTIVE')->where('r.code', 'ADMIN_BANJAR')->exists();
                if (! $domainReady || ! $adminReady) {
                    throw ValidationException::withMessages(['status' => 'Domain terverifikasi dan Admin Banjar aktif wajib tersedia.']);
                }
            }
            DB::table('banjars')->where('id', $id)->update($data + ['updated_at' => now()]);
            $this->audit->record($id, $actor->id, 'tenant.status_changed', 'banjar', $id, ['status' => $banjar->status], $data);
            RequestMemo::flush();
        });
    }

    public function module(User $actor, int $id, string $code, array $input): void
    {
        $this->access->requirePlatform($actor);
        abort_unless(array_key_exists($code, config('platform.modules')), 404);
        $data = Validator::make($input, ['status' => ['required', Rule::in($code === 'ACTIVITY' ? ['INACTIVE', 'BASIC', 'ACTIVE'] : ['INACTIVE', 'ACTIVE'])]])->validate();
        DB::transaction(function () use ($actor, $id, $code, $data) {
            abort_unless(DB::table('banjars')->where('id', $id)->lockForUpdate()->first(), 404);
            $modules = DB::table('banjar_modules')->where('banjar_id', $id)->pluck('status', 'code')->all();
            $old = $modules[$code] ?? 'INACTIVE';
            $modules[$code] = $data['status'];
            foreach (config('platform.dependencies') as $module => $dependencies) {
                if (($modules[$module] ?? null) !== 'ACTIVE') {
                    continue;
                }
                foreach ($dependencies as $dependency) {
                    if (($modules[$dependency] ?? null) !== 'ACTIVE') {
                        throw ValidationException::withMessages(['status' => $module.' memerlukan modul '.$dependency.' aktif.']);
                    }
                }
            }
            DB::table('banjar_modules')->where('banjar_id', $id)->where('code', $code)->update($data + ['updated_at' => now()]);
            RequestMemo::flush();
            $this->audit->record($id, $actor->id, 'module.changed', 'module', $code, ['status' => $old], $data + ['code' => $code]);
        });
    }
}

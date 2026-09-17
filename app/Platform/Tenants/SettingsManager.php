<?php

namespace App\Platform\Tenants;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SettingsManager
{
    public function __construct(private Access $access, private ModuleGate $modules, private CurrentBanjar $tenant, private TenantQuery $query, private Audit $audit) {}

    public function update(User $actor, int $id, array $input): void
    {
        $this->access->requireTenant($actor, 'settings.manage');
        $this->modules->authorize('WEBSITE');
        $data = Validator::make($input, ['template_code' => ['required', Rule::in(['template-01'])], 'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']])->validate();
        DB::transaction(function () use ($actor, $id, $data) {
            $old = $this->query->table('banjar_settings')->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404);
            $this->query->table('banjar_settings')->where('id', $id)->update($data + ['updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'settings.updated', 'settings', $id, (array) $old, $data);
        });
    }
}

<?php

namespace App\Modules\Asset;

use App\Models\User;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;

final class AssetAccess
{
    public function __construct(private Access $access, private ModuleGate $gate) {}

    public function authorize(User $actor, string $permission = 'asset.view'): void
    {
        $this->gate->authorize('ASSET');
        $this->access->requireTenant($actor, $permission);
    }

    public function can(User $actor, string $permission = 'asset.view'): bool
    {
        return $this->gate->allows('ASSET') && $this->access->tenant($actor, $permission);
    }
}

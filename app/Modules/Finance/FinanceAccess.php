<?php

namespace App\Modules\Finance;

use App\Models\User;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;

final class FinanceAccess
{
    public function __construct(private Access $access, private ModuleGate $gate) {}

    public function authorize(User $actor, string $permission = 'finance.view'): void
    {
        $this->gate->authorize('FINANCE');
        $this->gate->authorize('KRAMA');
        // Finance is Banjar-wide; unit assignments never grant tenant-wide money access.
        $this->access->requireTenant($actor, $permission);
    }
}

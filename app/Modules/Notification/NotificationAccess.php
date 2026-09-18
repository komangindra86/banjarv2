<?php

namespace App\Modules\Notification;

use App\Models\User;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;

final class NotificationAccess
{
    public function __construct(private Access $access, private ModuleGate $gate) {}

    public function authorize(User $actor): void
    {
        $this->gate->authorize('NOTIFICATION');
        $this->access->requireTenant($actor, 'dashboard.view');
    }
}

<?php

namespace App\Modules\Payment;

use App\Models\User;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;

final class PaymentAccess
{
    public function __construct(private Access $access, private ModuleGate $gate, private TenantQuery $query) {}

    public function authorize(User $actor, string $permission): void
    {
        foreach (['PAYMENT', 'FINANCE', 'KRAMA'] as $module) {
            $this->gate->authorize($module);
        }
        $this->access->requireTenant($actor, $permission);
    }

    public function can(User $actor, string $permission): bool
    {
        return $this->access->tenant($actor, $permission);
    }

    public function bills(User $actor, bool $pay = false): Builder
    {
        $permission = $pay ? 'payment.create' : 'payment.view';
        if ($this->access->tenant($actor, $permission)) {
            $this->authorize($actor, $permission);

            return $this->query->table('bills');
        }
        $this->authorize($actor, 'payment.self');
        $households = $this->query->table('krama')->where('user_id', $actor->id)->where('status', 'ACTIVE')->select('household_id');

        return $this->query->table('bills')->whereIn('kk_id', $households)
            ->whereIn('kk_id', $this->query->table('households')->where('status', 'ACTIVE')->select('id'));
    }

    public function bill(User $actor, int $id, bool $pay = false): object
    {
        $bill = $this->bills($actor, $pay)->where('id', $id)->first();
        abort_unless($bill, 404);

        return $bill;
    }
}

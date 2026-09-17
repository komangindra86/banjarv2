<?php

namespace App\Modules\Activity;

use App\Models\User;
use App\Modules\Krama\KramaAccess;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;

final class ActivityAccess
{
    public function __construct(private Access $access, private ModuleGate $gate, private TenantQuery $query, private KramaAccess $scope) {}

    // Operational features need ACTIVITY at ACTIVE level (BASIC only covers the public agenda) and KRAMA.
    public function authorize(User $actor, string $permission): void
    {
        $this->gate->authorize('ACTIVITY', 'ACTIVE');
        $this->gate->authorize('KRAMA');
        $this->access->requireTenant($actor, $permission);
    }

    public function can(User $actor, string $permission): bool
    {
        return $this->gate->allows('ACTIVITY', 'ACTIVE') && $this->gate->allows('KRAMA') && $this->access->tenant($actor, $permission);
    }

    public function activity(User $actor, int $id, string $permission = 'activity.view', bool $lock = false): object
    {
        $this->authorize($actor, $permission);
        $row = $this->query->table('activities')->where('id', $id)->where('status', '<>', 'ARCHIVED')->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($row, 404);

        return $row;
    }

    // Participants limited to the actor's unit scope for the given permission (null scope = whole Banjar).
    public function participants(User $actor, int $activityId, string $permission = 'activity.view'): Builder
    {
        $this->authorize($actor, $permission);

        return $this->query->table('activity_participants')->where('activity_participants.activity_id', $activityId)
            ->when($this->scope->unitIds($actor, $permission) !== null, fn ($q) => $q->where(function ($q) use ($actor, $permission) {
                $q->whereIn('activity_participants.household_id', $this->scope->households($actor, $permission)->select('households.id'))
                    ->orWhereIn('activity_participants.krama_id', $this->scope->people($actor, $permission)->select('krama.id'));
            }));
    }

    public function self(User $actor): ?object
    {
        $this->authorize($actor, 'activity.self');

        return $this->query->table('krama')->where('user_id', $actor->id)->where('status', 'ACTIVE')
            ->whereIn('household_id', $this->query->table('households')->where('status', 'ACTIVE')->select('id'))->first();
    }
}

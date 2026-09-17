<?php

namespace App\Modules\Krama;

use App\Models\User;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;

final class KramaAccess
{
    public function __construct(private Access $access, private ModuleGate $gate, private TenantQuery $query) {}

    public function authorize(User $actor, string $permission): void
    {
        $this->gate->authorize('KRAMA');
        $this->access->requireTenant($actor, $permission);
    }

    // null means the whole Banjar; an empty array grants no objects.
    public function unitIds(User $actor, string $permission): ?array
    {
        $this->authorize($actor, $permission);
        $assignments = $this->access->assignments($actor, $permission);
        if ($assignments->contains('scope_type', 'BANJAR')) {
            return null;
        }
        $units = $this->query->table('organizational_units')->where('is_active', true)->get();
        $allowed = [];
        foreach ($assignments as $assignment) {
            if (! $units->contains('id', $assignment->unit_id)) {
                continue;
            }
            $branch = [(int) $assignment->unit_id];
            if ($assignment->include_descendants) {
                do {
                    $previous = count($branch);
                    foreach ($units as $unit) {
                        if (in_array((int) $unit->parent_id, $branch, true) && ! in_array((int) $unit->id, $branch, true)) {
                            $branch[] = (int) $unit->id;
                        }
                    }
                } while (count($branch) !== $previous);
            }
            $allowed = array_merge($allowed, $branch);
        }

        return array_values(array_unique($allowed));
    }

    public function households(User $actor, string $permission = 'krama.view'): Builder
    {
        $ids = $this->unitIds($actor, $permission);
        $q = $this->query->table('households');
        if ($ids !== null) {
            $q->whereIn('households.id', $this->query->table('household_unit_memberships')->whereIn('unit_id', $ids)->select('household_id'));
        }

        return $q;
    }

    public function people(User $actor, string $permission = 'krama.view'): Builder
    {
        $ids = $this->unitIds($actor, $permission);
        $q = $this->query->table('krama');
        if ($ids !== null) {
            $q->where(function ($q) use ($ids) {
                $q->whereIn('household_id', $this->query->table('household_unit_memberships')->whereIn('unit_id', $ids)->select('household_id'))
                    ->orWhereIn('krama.id', $this->query->table('krama_unit_memberships')->whereIn('unit_id', $ids)->select('krama_id'));
            });
        }

        return $q;
    }

    public function units(User $actor, string $permission = 'krama.view'): Builder
    {
        $ids = $this->unitIds($actor, $permission);

        return $this->query->table('organizational_units')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name');
    }
}

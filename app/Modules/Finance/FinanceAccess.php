<?php

namespace App\Modules\Finance;

use App\Models\User;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;

final class FinanceAccess
{
    public function __construct(private Access $access, private ModuleGate $gate, private TenantQuery $query) {}

    public function authorize(User $actor, string $permission = 'finance.view'): void
    {
        $this->gate->authorize('FINANCE');
        $this->gate->authorize('KRAMA');
        // Finance cash bookkeeping is Banjar-wide; unit assignments never grant tenant-wide money access.
        // Billing/dedosan issuance (billing.issue, dedosan.issue) MAY be unit-scoped — see unitIds().
        $this->access->requireTenant($actor, $permission);
    }

    // Untuk halaman yang perlu diakses baik oleh finance.view (Petengen/Kelian/Admin) maupun Kesinoman
    // yang hanya punya billing.issue/dedosan.issue — tanpa memberi Kesinoman akses ke buku kas/laporan
    // penuh (yang tetap memakai authorize() default finance.view saja).
    public function authorizeAny(User $actor, array $permissions): void
    {
        $this->gate->authorize('FINANCE');
        $this->gate->authorize('KRAMA');
        foreach ($permissions as $permission) {
            if ($this->access->tenant($actor, $permission)) {
                return;
            }
        }
        abort(403);
    }

    // null = whole Banjar; [] = no units allowed. Mirrors KramaAccess::unitIds() exactly.
    // Only meaningful for billing.issue/dedosan.issue — those are the only Finance permissions
    // exempted from the scope_type=BANJAR filter in Access::assignments().
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
}

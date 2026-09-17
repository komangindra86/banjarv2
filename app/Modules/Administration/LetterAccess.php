<?php

namespace App\Modules\Administration;

use App\Models\User;
use App\Modules\Krama\KramaAccess;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;

final class LetterAccess
{
    public function __construct(private Access $access, private ModuleGate $gate, private TenantQuery $query, private KramaAccess $scope) {}

    public function authorize(User $actor, string $permission): void
    {
        $this->gate->authorize('ADMINISTRATION');
        $this->gate->authorize('KRAMA');
        $this->access->requireTenant($actor, $permission);
    }

    public function applicant(User $actor): ?object
    {
        $this->authorize($actor, 'letter.submit');

        return $this->query->table('krama')->where('user_id', $actor->id)->where('status', 'ACTIVE')
            ->whereIn('household_id', $this->query->table('households')->where('status', 'ACTIVE')->select('id'))->first();
    }

    public function visible(User $actor, bool $portal = false): Builder
    {
        $this->authorize($actor, $portal ? 'letter.submit' : 'letter.view');
        $q = $this->query->table('letter_requests');
        if ($portal) {
            // Reassigning a krama account must not expose previous users' requests.
            return $q->where('requester_id', $actor->id);
        }

        return $q->whereIn('krama_id', $this->scope->people($actor, 'letter.view')->select('krama.id'));
    }

    public function canReview(User $actor, object $row): bool
    {
        $permission = $row->status === 'SUBMITTED' ? 'letter.verify' : 'letter.approve';

        return in_array($row->status, ['SUBMITTED', 'VERIFIED'], true)
            && $row->requester_id !== $actor->id
            && ($row->status !== 'VERIFIED' || $row->verifier_id !== $actor->id)
            && $this->access->tenant($actor, $permission)
            && $this->scope->people($actor, $permission)->where('id', $row->krama_id)->exists();
    }

    public function requireReview(User $actor, object $row, string $permission): void
    {
        $this->authorize($actor, $permission);
        abort_unless($this->scope->people($actor, $permission)->where('id', $row->krama_id)->exists(), 404);
        abort_if($row->requester_id === $actor->id || ($permission === 'letter.approve' && $row->verifier_id === $actor->id), 403, 'Pengaju, Penyarikan, dan pemberi persetujuan harus berbeda.');
    }
}

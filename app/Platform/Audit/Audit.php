<?php

namespace App\Platform\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Audit
{
    public function record(?int $tenant, ?int $actor, string $action, string $entity, int|string|null $id, array $old = [], array $new = []): void
    {
        // Allowlist keeps credentials, account numbers and future PII out of snapshots.
        $allowed = ['name', 'slug', 'status', 'domain', 'template_code', 'accent_color', 'code', 'role_id', 'user_id', 'scope_type', 'unit_id', 'include_descendants', 'krama_changes_require_approval', 'letters_enabled', 'letter_requires_approval', 'letter_version', 'amount', 'gross_amount', 'direction', 'cash_account_id', 'bill_id', 'billing_event_id', 'reversal_of_id', 'dedosan_requires_approval', 'payout_requires_kelian_approval', 'bank_code', 'bank_account_id', 'account_number_masked', 'condition', 'quantity', 'location_id', 'category_id', 'kind', 'asset_id', 'loan_id', 'opname_id', 'method', 'count', 'target_kk_count', 'target_total_amount', 'scope_unit_ids', 'period_from', 'period_to', 'version', 'report_id'];
        $redact = fn (array $data) => array_intersect_key($data, array_flip($allowed));
        DB::table('audit_logs')->insert([
            'banjar_id' => $tenant, 'actor_id' => $actor, 'action' => $action,
            'entity_type' => $entity, 'entity_id' => $id,
            'old_values' => json_encode($redact($old), JSON_THROW_ON_ERROR),
            'new_values' => json_encode($redact($new), JSON_THROW_ON_ERROR),
            'request_id' => app()->runningInConsole() ? (string) Str::uuid() : request()->attributes->get('audit_request_id', (string) Str::uuid()),
            'created_at' => now(),
        ]);
    }
}

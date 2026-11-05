<?php

namespace App\Platform\Tenancy;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TenantQuery
{
    public function __construct(private CurrentBanjar $tenant) {}

    public function table(string $table): Builder
    {
        // Explicit catalog: platform/global tables cannot accidentally enter this path.
        if (! in_array($table, ['activity_targets', 'activity_participants', 'dedosan_candidates', 'activity_minutes', 'activity_decisions', 'activity_attachments', 'withdrawals', 'withdrawal_steps', 'banjar_bank_accounts', 'cash_accounts', 'cash_transactions', 'cash_transaction_categories', 'billing_types', 'billing_events', 'billing_event_subjects', 'billing_event_targets', 'bills', 'bill_settlements', 'finance_reports', 'letter_requests', 'letter_request_steps', 'letter_sequences', 'issued_documents', 'organizational_units', 'households', 'krama', 'household_unit_memberships', 'krama_unit_memberships', 'krama_change_requests', 'krama_change_items', 'banjar_settings', 'banjar_modules', 'banjar_user_memberships', 'tenant_role_assignments', 'audit_logs', 'tenant_domains', 'website_profiles', 'media_files', 'activities', 'announcements', 'news_posts', 'public_officials', 'service_definitions', 'public_documents', 'galleries', 'gallery_items', 'content_photos', 'asset_categories', 'asset_locations', 'assets', 'asset_movements', 'asset_loans', 'asset_loan_items', 'asset_returns', 'asset_inspections', 'asset_maintenance', 'stock_opnames', 'stock_opname_items', 'asset_disposals', 'push_subscriptions', 'notifications', 'notification_deliveries'], true)) {
            throw new InvalidArgumentException('Table is not registered as tenant-owned.');
        }

        return DB::table($table)->where($table.'.banjar_id', $this->tenant->id());
    }
}

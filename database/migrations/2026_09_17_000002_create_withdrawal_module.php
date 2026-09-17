<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function owned(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('banjar_id')->constrained()->restrictOnDelete();
        $t->unique(['banjar_id', 'id']);
        $t->timestamps();
    }

    private function ref(Blueprint $t, string $column, string $table): void
    {
        $t->foreign(['banjar_id', $column])->references(['banjar_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function up(): void
    {
        // Immutable account versions: number/holder/bank are never updated, only status moves.
        Schema::create('banjar_bank_accounts', function (Blueprint $t) {
            $this->owned($t);
            $t->string('bank_code', 20);
            $t->string('bank_name', 100);
            $t->text('account_number_encrypted');
            $t->string('account_number_masked', 20);
            $t->string('account_holder_name', 150);
            $t->enum('verification_status', ['PENDING', 'VERIFIED', 'REJECTED', 'REPLACED', 'DISABLED'])->default('PENDING');
            $t->boolean('is_primary')->default(false);
            $t->unsignedTinyInteger('primary_slot')->nullable()->storedAs('IF(is_primary, 1, NULL)');
            $t->unique(['banjar_id', 'primary_slot'], 'bank_account_one_primary');
            $t->unsignedTinyInteger('pending_slot')->nullable()->storedAs("IF(verification_status = 'PENDING', 1, NULL)");
            $t->unique(['banjar_id', 'pending_slot'], 'bank_account_one_pending');
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 500)->nullable();
            $t->index(['verification_status', 'created_at']);
        });
        Schema::create('withdrawals', function (Blueprint $t) {
            $this->owned($t);
            $t->string('reference', 40)->unique();
            $t->uuid('request_key');
            $t->unique(['banjar_id', 'request_key']);
            $t->unsignedBigInteger('bank_account_id');
            $this->ref($t, 'bank_account_id', 'banjar_bank_accounts');
            // Destination snapshot taken at request time; later account changes never redirect it.
            $t->string('bank_code', 20);
            $t->string('bank_name', 100);
            $t->text('account_number_encrypted');
            $t->string('account_number_masked', 20);
            $t->string('account_holder_name', 150);
            $t->unsignedBigInteger('amount');
            $t->boolean('requires_kelian_approval');
            $t->enum('status', ['APPROVAL_PENDING', 'REQUESTED', 'PROCESSING', 'PAID', 'FAILED', 'CANCELLED']);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('claimed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('claimed_at')->nullable();
            $t->foreignId('processed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('processed_at')->nullable();
            $t->text('bank_reference_encrypted')->nullable();
            $t->char('bank_reference_hash', 64)->nullable()->unique();
            $t->timestamp('transferred_at')->nullable();
            $t->string('proof_path', 200)->nullable();
            $t->string('proof_mime', 50)->nullable();
            $t->char('proof_sha256', 64)->nullable();
            $t->unsignedInteger('proof_size')->nullable();
            $t->string('closing_note', 500)->nullable();
            $t->index(['status', 'created_at']);
        });
        Schema::create('withdrawal_steps', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('withdrawal_id');
            $this->ref($t, 'withdrawal_id', 'withdrawals');
            $t->string('action', 30);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->enum('actor_scope', ['TENANT', 'PLATFORM']);
            $t->string('note', 500)->nullable();
        });
        DB::statement('ALTER TABLE withdrawals ADD CONSTRAINT withdrawal_amount CHECK (amount > 0 AND amount <= 999999999999)');
        DB::statement("ALTER TABLE withdrawals ADD CONSTRAINT withdrawal_paid_evidence CHECK (status <> 'PAID' OR (bank_reference_hash IS NOT NULL AND bank_reference_encrypted IS NOT NULL AND transferred_at IS NOT NULL AND proof_path IS NOT NULL AND proof_sha256 IS NOT NULL AND processed_by IS NOT NULL AND processed_at IS NOT NULL))");
        DB::statement("ALTER TABLE withdrawals ADD CONSTRAINT withdrawal_processing_claim CHECK (status NOT IN ('PROCESSING', 'PAID', 'FAILED') OR (claimed_by IS NOT NULL AND claimed_at IS NOT NULL))");
        DB::statement("ALTER TABLE banjar_bank_accounts ADD CONSTRAINT bank_primary_verified CHECK (is_primary = 0 OR verification_status = 'VERIFIED')");

        // Ledger journals now reference exactly one source: a payment or a withdrawal.
        DB::statement('ALTER TABLE financial_ledger_transactions MODIFY payment_id BIGINT UNSIGNED NULL');
        Schema::table('financial_ledger_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('withdrawal_id')->nullable()->after('payment_id');
            $this->ref($t, 'withdrawal_id', 'withdrawals');
        });
        DB::statement('ALTER TABLE financial_ledger_transactions ADD CONSTRAINT ledger_single_source CHECK ((payment_id IS NULL) <> (withdrawal_id IS NULL))');
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        // Never discard real payout history in production; test databases roll back freely.
        abort_if(app()->environment('production') && DB::table('financial_ledger_transactions')->whereNotNull('withdrawal_id')->exists(), 409, 'Withdrawal journals exist; rollback would destroy financial history.');
        DB::table('financial_ledger_entries')->whereIn('transaction_id', DB::table('financial_ledger_transactions')->whereNotNull('withdrawal_id')->select('id'))->delete();
        DB::table('financial_ledger_transactions')->whereNotNull('withdrawal_id')->delete();
        DB::statement('ALTER TABLE financial_ledger_transactions DROP CHECK ledger_single_source');
        Schema::table('financial_ledger_transactions', function (Blueprint $t) {
            $t->dropForeign(['banjar_id', 'withdrawal_id']);
            $t->dropColumn('withdrawal_id');
        });
        DB::statement('ALTER TABLE financial_ledger_transactions MODIFY payment_id BIGINT UNSIGNED NOT NULL');
        Schema::dropIfExists('withdrawal_steps');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('banjar_bank_accounts');
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('code', ['withdrawal.view', 'withdrawal.request', 'withdrawal.approve', 'bank.request', 'withdrawal.configure', 'platform.withdrawal.process', 'bank.verify'])->pluck('id'))->delete();
        DB::table('permissions')->whereIn('code', ['withdrawal.view', 'withdrawal.request', 'withdrawal.approve', 'bank.request', 'withdrawal.configure', 'platform.withdrawal.process', 'bank.verify'])->delete();
    }
};

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
        Schema::create('payments', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('bill_id');
            $this->ref($t, 'bill_id', 'bills');
            $t->string('order_id', 40)->unique();
            $t->string('midtrans_transaction_id', 100)->nullable()->unique();
            $t->string('merchant_id', 100);
            $t->boolean('production');
            $t->unsignedBigInteger('gross_amount');
            $t->unsignedBigInteger('gateway_fee')->nullable();
            $t->unsignedBigInteger('platform_fee')->default(0);
            $t->unsignedBigInteger('net_amount')->nullable();
            $t->unsignedSmallInteger('hold_days')->nullable();
            $t->string('payment_method', 10)->default('QRIS');
            $t->enum('status', ['CREATING', 'UNKNOWN', 'PENDING', 'EXPIRED', 'DENIED', 'CANCELLED', 'PAID', 'EXCEPTION'])->default('CREATING');
            $t->text('qr_url')->nullable();
            $t->boolean('blocked')->default(false);
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('settlement_at')->nullable();
            $t->timestamp('available_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('next_check_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->index(['status', 'next_check_at']);
        });
        Schema::create('payment_attempt_locks', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('bill_id');
            $t->unsignedBigInteger('active_payment_id')->nullable();
            $this->ref($t, 'bill_id', 'bills');
            $this->ref($t, 'active_payment_id', 'payments');
            $t->unique(['banjar_id', 'bill_id']);
        });
        Schema::table('bill_settlements', function (Blueprint $t) {
            $t->unsignedBigInteger('payment_id')->nullable();
            $this->ref($t, 'payment_id', 'payments');
            $t->unique(['banjar_id', 'payment_id']);
        });
        Schema::create('payment_webhook_receipts', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('payment_id');
            $this->ref($t, 'payment_id', 'payments');
            $t->char('fingerprint', 64)->unique();
            $t->json('payload');
            $t->enum('status', ['RECEIVED', 'RETRY', 'PROCESSED'])->default('RECEIVED');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('last_error', 50)->nullable();
            $t->index(['status', 'next_attempt_at']);
        });
        Schema::create('financial_accounts', function (Blueprint $t) {
            $this->owned($t);
            $t->enum('bucket', ['CLEARING', 'PENDING', 'AVAILABLE', 'RESERVED', 'PAID_OUT', 'FEE', 'EXCEPTION']);
            $t->string('currency', 3)->default('IDR');
            $t->unique(['banjar_id', 'bucket', 'currency'], 'financial_account_bucket');
        });
        Schema::create('financial_ledger_transactions', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('payment_id');
            $this->ref($t, 'payment_id', 'payments');
            $t->string('type', 40);
            $t->string('idempotency_key', 100)->unique();
            $t->char('payload_hash', 64);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('financial_ledger_entries', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('account_id');
            $this->ref($t, 'transaction_id', 'financial_ledger_transactions');
            $this->ref($t, 'account_id', 'financial_accounts');
            $t->enum('direction', ['DEBIT', 'CREDIT']);
            $t->unsignedBigInteger('amount');
        });
        Schema::create('reconciliation_runs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('checked')->default(0);
            $t->unsignedInteger('failed')->default(0);
        });
        Schema::create('reconciliation_items', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('payment_id');
            $this->ref($t, 'payment_id', 'payments');
            $t->string('code', 50);
            $t->string('status', 20)->default('OPEN');
            $t->unique(['payment_id', 'code']);
        });
        Schema::create('payment_settlement_evidence', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('payment_id');
            $this->ref($t, 'payment_id', 'payments');
            $t->unique('payment_id');
            $t->text('reference');
            $t->unsignedBigInteger('gateway_fee');
            $t->timestamp('settled_at');
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payment_amounts CHECK (gross_amount > 0 AND gross_amount <= 999999999999 AND platform_fee = 0 AND (gateway_fee IS NULL OR gateway_fee < gross_amount) AND (net_amount IS NULL OR net_amount = gross_amount - gateway_fee - platform_fee))');
        DB::statement('ALTER TABLE financial_ledger_entries ADD CONSTRAINT ledger_positive CHECK (amount > 0 AND amount <= 999999999999)');
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        foreach (['payment_settlement_evidence', 'reconciliation_items', 'reconciliation_runs', 'financial_ledger_entries', 'financial_ledger_transactions', 'financial_accounts', 'payment_webhook_receipts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('bill_settlements', function (Blueprint $t) {
            $t->dropForeign(['banjar_id', 'payment_id']);
            $t->dropUnique(['banjar_id', 'payment_id']);
            $t->dropColumn('payment_id');
        });
        Schema::dropIfExists('payment_attempt_locks');
        Schema::dropIfExists('payments');
    }
};

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

    private function reference(Blueprint $t, string $column, string $table): void
    {
        $t->foreign(['banjar_id', $column])->references(['banjar_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::table('banjar_settings', fn (Blueprint $t) => $t->boolean('dedosan_requires_approval')->default(true));
        Schema::create('cash_accounts', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 100);
            $t->unique(['banjar_id', 'name']);
        });
        Schema::create('billing_types', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 100);
            $t->enum('kind', ['IURAN', 'DEDOSAN', 'OTHER']);
            $t->unsignedBigInteger('default_amount');
            $t->boolean('is_active')->default(true);
            $t->unique(['banjar_id', 'name']);
        });
        Schema::create('billing_events', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('billing_type_id');
            $this->reference($t, 'billing_type_id', 'billing_types');
            $t->string('reference', 80);
            $t->string('title', 150);
            $t->enum('kind', ['IURAN', 'DEDOSAN', 'OTHER']);
            $t->unsignedBigInteger('amount');
            $t->date('due_date')->nullable();
            $t->enum('target', ['ALL_ACTIVE', 'UNIT', 'INDIVIDUAL']);
            $t->unsignedBigInteger('unit_id')->nullable();
            $this->reference($t, 'unit_id', 'organizational_units');
            $t->enum('status', ['DRAFT', 'APPROVED', 'REJECTED', 'ISSUED', 'VOID'])->default('DRAFT');
            $t->boolean('requires_approval');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->unique(['banjar_id', 'reference']);
            $t->index(['banjar_id', 'status']);
        });
        Schema::create('billing_event_subjects', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('billing_event_id');
            $t->unsignedBigInteger('kk_id');
            $t->unsignedBigInteger('subject_krama_id');
            $t->text('reason');
            $this->reference($t, 'billing_event_id', 'billing_events');
            $this->reference($t, 'kk_id', 'households');
            $this->reference($t, 'subject_krama_id', 'krama');
            // Historical household is a snapshot: a later move must not rewrite it.
            $t->unique(['banjar_id', 'billing_event_id', 'kk_id'], 'dedosan_one_subject_per_kk');
        });
        Schema::create('bills', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('billing_event_id');
            $t->unsignedBigInteger('kk_id');
            $t->unsignedBigInteger('subject_krama_id')->nullable();
            $t->string('household_code', 40);
            $t->text('subject_name')->nullable();
            $t->enum('kind', ['IURAN', 'DEDOSAN', 'OTHER']);
            $t->unsignedBigInteger('gross_amount');
            $t->enum('status', ['UNPAID', 'PAID', 'VOID'])->default('UNPAID');
            $this->reference($t, 'billing_event_id', 'billing_events');
            $this->reference($t, 'kk_id', 'households');
            $this->reference($t, 'subject_krama_id', 'krama');
            $t->unique(['banjar_id', 'billing_event_id', 'kk_id'], 'bill_one_per_event_kk');
            $t->index(['banjar_id', 'status']);
        });
        Schema::create('cash_transactions', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('cash_account_id');
            $this->reference($t, 'cash_account_id', 'cash_accounts');
            $t->enum('direction', ['IN', 'OUT']);
            $t->unsignedBigInteger('amount');
            $t->date('transaction_date');
            $t->text('reason');
            $t->string('reference', 100);
            $t->enum('source', ['MANUAL', 'BILL', 'REVERSAL']);
            $t->unsignedBigInteger('bill_id')->nullable();
            $this->reference($t, 'bill_id', 'bills');
            $t->unsignedBigInteger('reversal_of_id')->nullable();
            $this->reference($t, 'reversal_of_id', 'cash_transactions');
            $t->uuid('submission_key');
            $t->char('submission_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['banjar_id', 'submission_key']);
            $t->unique(['banjar_id', 'reversal_of_id']);
            $t->index(['banjar_id', 'transaction_date']);
        });
        Schema::create('bill_settlements', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('bill_id');
            $this->reference($t, 'bill_id', 'bills');
            $t->enum('method', ['MANUAL', 'GATEWAY']);
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('cash_transaction_id')->nullable();
            $this->reference($t, 'cash_transaction_id', 'cash_transactions');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->unsignedBigInteger('active_bill_id')->storedAs('CASE WHEN reversed_at IS NULL THEN bill_id ELSE NULL END');
            $t->unique(['banjar_id', 'active_bill_id']);
            $t->unique(['banjar_id', 'cash_transaction_id']);
        });
        DB::statement('ALTER TABLE billing_types ADD CONSTRAINT billing_type_amount CHECK (default_amount > 0 AND default_amount <= 999999999999)');
        DB::statement('ALTER TABLE billing_events ADD CONSTRAINT billing_event_amount CHECK (amount > 0 AND amount <= 999999999999)');
        DB::statement("ALTER TABLE billing_events ADD CONSTRAINT billing_event_target CHECK ((kind = 'DEDOSAN' AND target = 'INDIVIDUAL' AND unit_id IS NULL) OR (kind <> 'DEDOSAN' AND ((target = 'ALL_ACTIVE' AND unit_id IS NULL) OR (target = 'UNIT' AND unit_id IS NOT NULL))))");
        DB::statement("ALTER TABLE bills ADD CONSTRAINT bill_subject CHECK ((kind = 'DEDOSAN' AND subject_krama_id IS NOT NULL) OR (kind <> 'DEDOSAN' AND subject_krama_id IS NULL))");
        DB::statement('ALTER TABLE bills ADD CONSTRAINT bill_amount CHECK (gross_amount > 0 AND gross_amount <= 999999999999)');
        DB::statement('ALTER TABLE cash_transactions ADD CONSTRAINT cash_amount CHECK (amount > 0 AND amount <= 999999999999)');
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_source CHECK ((source = 'MANUAL' AND bill_id IS NULL AND reversal_of_id IS NULL) OR (source = 'BILL' AND bill_id IS NOT NULL AND reversal_of_id IS NULL AND direction = 'IN') OR (source = 'REVERSAL' AND reversal_of_id IS NOT NULL))");
        DB::statement('ALTER TABLE bill_settlements ADD CONSTRAINT settlement_amount CHECK (amount > 0 AND amount <= 999999999999)');
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        foreach (['bill_settlements', 'cash_transactions', 'bills', 'billing_event_subjects', 'billing_events', 'billing_types', 'cash_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('banjar_settings', fn (Blueprint $t) => $t->dropColumn('dedosan_requires_approval'));
    }
};

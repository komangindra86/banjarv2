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
        Schema::create('asset_categories', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->unique(['banjar_id', 'name']);
        });
        Schema::create('asset_locations', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->unique(['banjar_id', 'name']);
        });
        Schema::create('assets', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('category_id');
            $this->ref($t, 'category_id', 'asset_categories');
            $t->unsignedBigInteger('location_id');
            $this->ref($t, 'location_id', 'asset_locations');
            $t->enum('kind', ['INDIVIDUAL', 'QUANTITY']);
            $t->string('code', 40);
            $t->string('name', 150);
            $t->unsignedInteger('quantity')->default(1);
            $t->unsignedInteger('quantity_on_loan')->default(0);
            $t->unsignedInteger('quantity_available')->storedAs('quantity - quantity_on_loan');
            $t->enum('condition', ['BAIK', 'PERLU_PERAWATAN', 'RUSAK_RINGAN', 'RUSAK_BERAT', 'HILANG', 'TIDAK_DIGUNAKAN'])->default('BAIK');
            $t->enum('status', ['ACTIVE', 'DISPOSED'])->default('ACTIVE');
            $t->date('acquisition_date')->nullable();
            $t->unsignedBigInteger('acquisition_cost')->nullable();
            $t->unsignedBigInteger('acquisition_cash_transaction_id')->nullable();
            $this->ref($t, 'acquisition_cash_transaction_id', 'cash_transactions');
            $t->string('notes', 1000)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['banjar_id', 'code']);
            $t->index(['banjar_id', 'status', 'category_id']);
            $t->index(['banjar_id', 'location_id']);
        });
        Schema::create('asset_movements', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('asset_id');
            $this->ref($t, 'asset_id', 'assets');
            $t->enum('type', ['ACQUIRED', 'TRANSFER', 'LOAN_OUT', 'LOAN_RETURN', 'CONDITION_CHANGE', 'ADJUSTMENT', 'DISPOSED']);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('from_location_id')->nullable();
            $this->ref($t, 'from_location_id', 'asset_locations');
            $t->unsignedBigInteger('to_location_id')->nullable();
            $this->ref($t, 'to_location_id', 'asset_locations');
            $t->string('note', 500)->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->index(['banjar_id', 'asset_id', 'created_at']);
        });
        Schema::create('asset_loans', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('borrower_krama_id')->nullable();
            $this->ref($t, 'borrower_krama_id', 'krama');
            $t->string('borrower_name', 150)->nullable();
            $t->string('purpose', 255);
            $t->date('loan_date');
            $t->date('due_date')->nullable();
            $t->enum('status', ['ACTIVE', 'PARTIALLY_RETURNED', 'RETURNED'])->default('ACTIVE');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->index(['banjar_id', 'status']);
        });
        Schema::create('asset_loan_items', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('loan_id');
            $this->ref($t, 'loan_id', 'asset_loans');
            $t->unsignedBigInteger('asset_id');
            $this->ref($t, 'asset_id', 'assets');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('returned_quantity')->default(0);
            $t->enum('condition_out', ['BAIK', 'PERLU_PERAWATAN', 'RUSAK_RINGAN', 'RUSAK_BERAT', 'HILANG', 'TIDAK_DIGUNAKAN']);
            $t->unique(['banjar_id', 'loan_id', 'asset_id']);
        });
        Schema::create('asset_returns', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('loan_item_id');
            $this->ref($t, 'loan_item_id', 'asset_loan_items');
            $t->unsignedInteger('quantity');
            $t->enum('condition_in', ['BAIK', 'PERLU_PERAWATAN', 'RUSAK_RINGAN', 'RUSAK_BERAT', 'HILANG', 'TIDAK_DIGUNAKAN']);
            $t->string('note', 500)->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
        });
        Schema::create('asset_inspections', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('asset_id');
            $this->ref($t, 'asset_id', 'assets');
            $t->enum('condition', ['BAIK', 'PERLU_PERAWATAN', 'RUSAK_RINGAN', 'RUSAK_BERAT', 'HILANG', 'TIDAK_DIGUNAKAN']);
            $t->string('note', 500)->nullable();
            $t->foreignId('inspector_id')->constrained('users')->restrictOnDelete();
            $t->index(['banjar_id', 'asset_id']);
        });
        Schema::create('asset_maintenance', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('asset_id');
            $this->ref($t, 'asset_id', 'assets');
            $t->string('description', 255);
            $t->enum('status', ['SCHEDULED', 'DONE'])->default('SCHEDULED');
            $t->date('scheduled_at')->nullable();
            $t->unsignedBigInteger('cost')->nullable();
            $t->unsignedBigInteger('cash_transaction_id')->nullable();
            $this->ref($t, 'cash_transaction_id', 'cash_transactions');
            $t->string('note', 500)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->index(['banjar_id', 'asset_id', 'status']);
        });
        Schema::create('stock_opnames', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('location_id')->nullable();
            $this->ref($t, 'location_id', 'asset_locations');
            $t->enum('status', ['DRAFT', 'COMPLETED'])->default('DRAFT');
            $t->string('note', 500)->nullable();
            $t->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('completed_at')->nullable();
        });
        Schema::create('stock_opname_items', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('opname_id');
            $this->ref($t, 'opname_id', 'stock_opnames');
            $t->unsignedBigInteger('asset_id');
            $this->ref($t, 'asset_id', 'assets');
            $t->unsignedInteger('expected_quantity');
            $t->unsignedInteger('counted_quantity')->nullable();
            $t->string('note', 500)->nullable();
            $t->unique(['banjar_id', 'opname_id', 'asset_id']);
        });
        Schema::create('asset_disposals', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('asset_id');
            $this->ref($t, 'asset_id', 'assets');
            $t->unsignedInteger('quantity');
            $t->enum('method', ['SOLD', 'DAMAGED_WRITE_OFF', 'LOST', 'DONATED', 'OTHER']);
            $t->string('reason', 500);
            $t->unsignedBigInteger('proceeds_cash_transaction_id')->nullable();
            $this->ref($t, 'proceeds_cash_transaction_id', 'cash_transactions');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->index(['banjar_id', 'asset_id']);
        });
        DB::statement("ALTER TABLE assets ADD CONSTRAINT asset_kind_quantity CHECK ((kind = 'INDIVIDUAL' AND quantity IN (0, 1)) OR (kind = 'QUANTITY' AND quantity >= 0))");
        DB::statement('ALTER TABLE assets ADD CONSTRAINT asset_loan_bounds CHECK (quantity_on_loan <= quantity)');
        DB::statement("ALTER TABLE assets ADD CONSTRAINT asset_status_shape CHECK (status = 'ACTIVE' OR (status = 'DISPOSED' AND quantity = 0 AND quantity_on_loan = 0))");
        DB::statement('ALTER TABLE asset_loan_items ADD CONSTRAINT loan_item_returned_bounds CHECK (returned_quantity <= quantity)');
        DB::statement('ALTER TABLE asset_loans ADD CONSTRAINT loan_borrower_identified CHECK (borrower_krama_id IS NOT NULL OR borrower_name IS NOT NULL)');
        DB::statement('ALTER TABLE asset_movements ADD CONSTRAINT movement_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE asset_returns ADD CONSTRAINT return_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE asset_disposals ADD CONSTRAINT disposal_quantity_positive CHECK (quantity > 0)');
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        foreach (['asset_disposals', 'stock_opname_items', 'stock_opnames', 'asset_maintenance', 'asset_inspections', 'asset_returns', 'asset_loan_items', 'asset_loans', 'asset_movements', 'assets', 'asset_locations', 'asset_categories'] as $table) {
            Schema::dropIfExists($table);
        }
        $codes = ['asset.view', 'asset.manage', 'asset.dispose'];
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('code', $codes)->pluck('id'))->delete();
        DB::table('permissions')->whereIn('code', $codes)->delete();
    }
};

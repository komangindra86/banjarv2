<?php

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
        Schema::create('cash_transaction_categories', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 100);
            $t->enum('kind', ['IN', 'OUT']);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->boolean('always_show')->default(false);
            $t->unique(['banjar_id', 'name']);
        });
        Schema::table('cash_transactions', function (Blueprint $t) {
            // Nullable: transaksi lama tetap sah dan tetap masuk total laporan sebagai "Belum dikategorikan".
            $t->unsignedBigInteger('category_id')->nullable()->after('cash_account_id');
            $this->reference($t, 'category_id', 'cash_transaction_categories');
        });
        Schema::table('cash_accounts', function (Blueprint $t) {
            // Membedakan kas tunai dari rekening bank untuk pengelompokan posisi dana pada laporan.
            $t->enum('type', ['CASH', 'BANK'])->default('CASH')->after('name');
        });
        DB::statement('ALTER TABLE cash_transaction_categories ADD CONSTRAINT category_sort_order CHECK (sort_order >= 0)');
    }

    public function down(): void
    {
        Schema::table('cash_accounts', fn (Blueprint $t) => $t->dropColumn('type'));
        Schema::table('cash_transactions', function (Blueprint $t) {
            $t->dropForeign(['banjar_id', 'category_id']);
            $t->dropColumn('category_id');
        });
        Schema::dropIfExists('cash_transaction_categories');
    }
};

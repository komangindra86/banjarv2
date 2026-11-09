<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bayar sekaligus (§29.3): satu QRIS dapat melunasi beberapa tagihan. Rincian per tagihan di payment_items;
// payments.bill_id tidak dipakai lagi (dibiarkan untuk data lama). Satu pembayaran → banyak bill_settlements.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained()->restrictOnDelete();
            $t->unique(['banjar_id', 'id']);
            $t->timestamps();
            $t->unsignedBigInteger('payment_id');
            $t->unsignedBigInteger('bill_id');
            $t->unsignedBigInteger('amount');
            $t->foreign(['banjar_id', 'payment_id'])->references(['banjar_id', 'id'])->on('payments')->restrictOnDelete();
            $t->foreign(['banjar_id', 'bill_id'])->references(['banjar_id', 'id'])->on('bills')->restrictOnDelete();
            $t->unique(['payment_id', 'bill_id']);
            $t->index(['banjar_id', 'bill_id']);
        });
        DB::statement('ALTER TABLE payment_items ADD CONSTRAINT payment_item_amount CHECK (amount > 0 AND amount <= 999999999999)');
        DB::statement('INSERT INTO payment_items (banjar_id, payment_id, bill_id, amount, created_at, updated_at)
            SELECT banjar_id, id, bill_id, COALESCE(bill_amount, gross_amount), created_at, updated_at FROM payments WHERE bill_id IS NOT NULL');

        Schema::table('payments', function (Blueprint $t) {
            $t->dropForeign(['banjar_id', 'bill_id']);
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedBigInteger('bill_id')->nullable()->change();
            $t->foreign(['banjar_id', 'bill_id'])->references(['banjar_id', 'id'])->on('bills')->restrictOnDelete();
        });
        Schema::table('bill_settlements', function (Blueprint $t) {
            $t->index(['banjar_id', 'payment_id'], 'bill_settlements_payment_index');
        });
        Schema::table('bill_settlements', function (Blueprint $t) {
            $t->dropUnique(['banjar_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        abort_if(DB::table('payment_items')->select('payment_id')->groupBy('payment_id')->havingRaw('COUNT(*) > 1')->exists(), 409, 'Ada pembayaran multi-tagihan; tidak dapat dikembalikan.');
        DB::statement('UPDATE payments p JOIN payment_items i ON i.payment_id = p.id SET p.bill_id = i.bill_id WHERE p.bill_id IS NULL');
        Schema::table('bill_settlements', function (Blueprint $t) {
            $t->unique(['banjar_id', 'payment_id']);
        });
        Schema::table('bill_settlements', function (Blueprint $t) {
            $t->dropIndex('bill_settlements_payment_index');
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->dropForeign(['banjar_id', 'bill_id']);
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedBigInteger('bill_id')->nullable(false)->change();
            $t->foreign(['banjar_id', 'bill_id'])->references(['banjar_id', 'id'])->on('bills')->restrictOnDelete();
        });
        Schema::dropIfExists('payment_items');
    }
};

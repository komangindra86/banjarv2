<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Pencairan dana QRIS yang sudah ditransfer dicatat otomatis di buku kas sebagai pemindahan (TRANSFER) ke rekening
// bank Banjar: menambah saldo rekening, tetapi bukan pendapatan (pendapatannya sudah dihitung saat warga membayar QRIS).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('withdrawal_id')->nullable()->after('reversal_of_id');
            $t->unique(['banjar_id', 'withdrawal_id']);
            $t->foreign(['banjar_id', 'withdrawal_id'])->references(['banjar_id', 'id'])->on('withdrawals');
        });
        DB::statement('ALTER TABLE cash_transactions DROP CHECK cash_source');
        DB::statement("ALTER TABLE cash_transactions MODIFY source ENUM('MANUAL', 'BILL', 'REVERSAL', 'TRANSFER') NOT NULL");
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_source CHECK ((source = 'MANUAL' AND bill_id IS NULL AND reversal_of_id IS NULL AND withdrawal_id IS NULL) OR (source = 'BILL' AND bill_id IS NOT NULL AND reversal_of_id IS NULL AND withdrawal_id IS NULL AND direction = 'IN') OR (source = 'REVERSAL' AND reversal_of_id IS NOT NULL AND withdrawal_id IS NULL) OR (source = 'TRANSFER' AND withdrawal_id IS NOT NULL AND bill_id IS NULL AND reversal_of_id IS NULL AND direction = 'IN'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cash_transactions DROP CHECK cash_source');
        DB::table('cash_transactions')->where('source', 'TRANSFER')->delete();
        DB::statement("ALTER TABLE cash_transactions MODIFY source ENUM('MANUAL', 'BILL', 'REVERSAL') NOT NULL");
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_source CHECK ((source = 'MANUAL' AND bill_id IS NULL AND reversal_of_id IS NULL) OR (source = 'BILL' AND bill_id IS NOT NULL AND reversal_of_id IS NULL AND direction = 'IN') OR (source = 'REVERSAL' AND reversal_of_id IS NOT NULL))");
        Schema::table('cash_transactions', function (Blueprint $t) {
            $t->dropForeign(['banjar_id', 'withdrawal_id']);
            $t->dropUnique(['banjar_id', 'withdrawal_id']);
            $t->dropColumn('withdrawal_id');
        });
    }
};

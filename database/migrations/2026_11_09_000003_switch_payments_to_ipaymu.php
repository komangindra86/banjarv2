<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// QRIS lewat iPaymu (akun Bali Santih): warga membayar tagihan + biaya layanan (service_fee). Banjar menerima nilai
// tagihan utuh (bill_amount); biaya layanan dikurangi biaya iPaymu menjadi pendapatan platform (kantong PLATFORM).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->renameColumn('midtrans_transaction_id', 'provider_transaction_id');
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->string('provider', 20)->default('IPAYMU')->after('order_id');
            $t->unsignedBigInteger('bill_amount')->nullable()->after('gross_amount');
            $t->unsignedBigInteger('service_fee')->default(0)->after('bill_amount');
            $t->text('qr_string')->nullable()->after('qr_url');
        });
        DB::table('payments')->update(['bill_amount' => DB::raw('gross_amount'), 'provider' => 'MIDTRANS']);
        // Model baru: total = tagihan + biaya layanan; bagian Banjar (net) = tagihan; pendapatan platform ≤ biaya layanan.
        DB::statement('ALTER TABLE payments DROP CHECK payment_amounts');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payment_amounts CHECK (gross_amount > 0 AND gross_amount <= 999999999999 AND bill_amount IS NOT NULL AND bill_amount > 0 AND gross_amount = bill_amount + service_fee AND platform_fee <= service_fee AND (gateway_fee IS NULL OR gateway_fee < gross_amount) AND (net_amount IS NULL OR net_amount = bill_amount))');
        DB::statement("ALTER TABLE financial_accounts MODIFY bucket ENUM('CLEARING', 'PENDING', 'AVAILABLE', 'RESERVED', 'PAID_OUT', 'FEE', 'EXCEPTION', 'PLATFORM') NOT NULL");
    }

    public function down(): void
    {
        // Kantong PLATFORM tidak ada di skema lama: entrinya digabung ke kantong FEE milik Banjar yang sama.
        foreach (DB::table('financial_accounts')->where('bucket', 'PLATFORM')->get() as $platform) {
            $fee = DB::table('financial_accounts')->where('banjar_id', $platform->banjar_id)->where('bucket', 'FEE')->where('currency', $platform->currency)->value('id')
                ?? DB::table('financial_accounts')->insertGetId(['banjar_id' => $platform->banjar_id, 'bucket' => 'FEE', 'currency' => $platform->currency, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('financial_ledger_entries')->where('account_id', $platform->id)->update(['account_id' => $fee]);
            DB::table('financial_accounts')->where('id', $platform->id)->delete();
        }
        DB::statement("ALTER TABLE financial_accounts MODIFY bucket ENUM('CLEARING', 'PENDING', 'AVAILABLE', 'RESERVED', 'PAID_OUT', 'FEE', 'EXCEPTION') NOT NULL");
        DB::statement('ALTER TABLE payments DROP CHECK payment_amounts');
        DB::table('payments')->update(['platform_fee' => 0, 'net_amount' => null]);
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payment_amounts CHECK (gross_amount > 0 AND gross_amount <= 999999999999 AND platform_fee = 0 AND (gateway_fee IS NULL OR gateway_fee < gross_amount) AND (net_amount IS NULL OR net_amount = gross_amount - gateway_fee - platform_fee))');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn(['provider', 'bill_amount', 'service_fee', 'qr_string']);
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->renameColumn('provider_transaction_id', 'midtrans_transaction_id');
        });
    }
};

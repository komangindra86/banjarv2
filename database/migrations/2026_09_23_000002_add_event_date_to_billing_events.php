<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Banjar tidak mengenal "jatuh tempo" — yang penting adalah tanggal kejadiannya sendiri (tanggal
    // kematian untuk patus, tanggal kegiatan untuk dedosan, periode berlaku untuk iuran). due_date tetap
    // ada (opsional) untuk Banjar yang mau memakainya, tapi event_date sekarang jadi informasi utama.
    public function up(): void
    {
        Schema::table('billing_events', function (Blueprint $t) {
            $t->date('event_date')->nullable()->after('reference');
        });
        DB::statement('UPDATE billing_events SET event_date = COALESCE(DATE(issued_at), DATE(created_at))');
        DB::statement('ALTER TABLE billing_events MODIFY event_date DATE NOT NULL');
    }

    public function down(): void
    {
        Schema::table('billing_events', fn (Blueprint $t) => $t->dropColumn('event_date'));
    }
};

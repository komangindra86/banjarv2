<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('krama', function (Blueprint $t) {
            // "Kepala Keluarga" bukan nilai yang tersimpan di sini — itu diturunkan dari households.head_krama_id
            // dan selalu menimpa tampilan. Kolom ini hanya untuk anggota lain (Istri/Suami, Anak, dst).
            $t->enum('relationship', ['ISTRI_SUAMI', 'ANAK', 'ORANG_TUA', 'MERTUA', 'FAMILI_LAIN'])->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('krama', fn (Blueprint $t) => $t->dropColumn('relationship'));
    }
};

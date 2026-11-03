<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// §23/§53: akun portal warga diaktifkan Admin Banjar; login dengan NIK, kata sandi awal wajib diganti.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('email')->nullable()->change();
            // HMAC NIK (bukan NIK asli) agar NIK tetap hanya tersimpan terenkripsi di tabel krama.
            $t->char('nik_hash', 64)->nullable()->unique()->after('email');
            $t->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['nik_hash']);
            $t->dropColumn(['nik_hash', 'must_change_password']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Akun demo untuk reviewer memakai kata sandi bersama yang diumumkan; kata sandinya dikunci agar tidak bisa diganti
// atau direset oleh pemakai demo lain.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('password_locked')->default(false)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('password_locked');
        });
    }
};

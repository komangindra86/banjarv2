<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Notifikasi warga untuk pengumuman & kegiatan yang terbit: notified_at menandai konten yang notifikasinya sudah dikirim.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['announcements', 'activities'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('notified_at')->nullable();
            });
            // Konten yang sudah terbit sebelum fitur ini ada tidak dikirim ulang ke warga.
            DB::table($table)->where('status', 'PUBLISHED')->where('published_at', '<=', now())->update(['notified_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['announcements', 'activities'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('notified_at');
            });
        }
    }
};

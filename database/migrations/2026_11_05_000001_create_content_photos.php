<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// §18: konten website (selain Galeri yang memakai gallery_items) bisa berisi beberapa foto; foto pertama tetap
// disalin ke kolom media_id sebagai foto utama (kartu, pratinjau tautan).
return new class extends Migration
{
    private const TABLES = ['pengumuman' => 'announcements', 'berita' => 'news_posts', 'agenda' => 'activities', 'pengurus' => 'public_officials', 'layanan' => 'service_definitions'];

    public function up(): void
    {
        Schema::create('content_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained();
            $t->string('content_type', 20);
            $t->unsignedBigInteger('content_id');
            $t->unsignedBigInteger('media_id');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->foreign(['banjar_id', 'media_id'])->references(['banjar_id', 'id'])->on('media_files');
            $t->unique(['content_type', 'content_id', 'media_id']);
            $t->index(['banjar_id', 'media_id']);
        });
        // Foto utama yang sudah ada menjadi foto pertama konten tersebut.
        foreach (self::TABLES as $type => $table) {
            DB::table($table)->whereNotNull('media_id')->orderBy('id')->chunkById(500, function ($rows) use ($type) {
                DB::table('content_photos')->insert($rows->map(fn ($row) => ['banjar_id' => $row->banjar_id, 'content_type' => $type, 'content_id' => $row->id, 'media_id' => $row->media_id, 'sort_order' => 0])->all());
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_photos');
    }
};

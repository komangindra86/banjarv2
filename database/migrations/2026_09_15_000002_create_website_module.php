<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained();
            $t->string('path')->unique();
            $t->string('name');
            $t->string('mime', 80);
            $t->unsignedBigInteger('size');
            $t->enum('visibility', ['PRIVATE', 'PUBLIC'])->default('PRIVATE');
            $t->foreignId('uploader_id')->constrained('users');
            $t->timestamps();
            $t->unique(['banjar_id', 'id']);
        });
        Schema::create('website_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->unique()->constrained();
            $t->string('tagline')->nullable();
            $t->text('description')->nullable();
            $t->text('history')->nullable();
            $t->text('address')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            foreach (['logo_id', 'cover_id'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
                $t->foreign(['banjar_id', $column])->references(['banjar_id', 'id'])->on('media_files');
            }
            $t->timestamps();
        });
        // Activities are owned by ACTIVITY and will be extended for attendance in phase 8.
        foreach (['activities', 'announcements', 'news_posts', 'public_officials', 'service_definitions', 'public_documents', 'galleries'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->foreignId('banjar_id')->constrained();
                $t->string('title', 180);
                $t->string('slug', 180);
                $t->text('body')->nullable();
                $t->enum('status', ['DRAFT', 'PUBLISHED', 'ARCHIVED'])->default('DRAFT');
                $t->timestamp('published_at')->nullable();
                $t->unsignedBigInteger('media_id')->nullable();
                $t->foreign(['banjar_id', 'media_id'])->references(['banjar_id', 'id'])->on('media_files');
                if ($table === 'announcements') {
                    $t->enum('priority', ['NORMAL', 'IMPORTANT'])->default('NORMAL');
                    $t->timestamp('expires_at')->nullable();
                    $t->unsignedBigInteger('related_activity_id')->nullable();
                    $t->foreign(['banjar_id', 'related_activity_id'])->references(['banjar_id', 'id'])->on('activities');
                }
                if ($table === 'activities') {
                    $t->dateTime('starts_at');
                    $t->dateTime('ends_at')->nullable();
                    $t->string('location')->nullable();
                }
                if ($table === 'public_officials') {
                    $t->string('position', 120);
                    $t->unsignedInteger('sort_order')->default(0);
                }
                if ($table === 'service_definitions') {
                    $t->text('requirements')->nullable();
                    $t->text('procedure')->nullable();
                    $t->string('contact')->nullable();
                }
                $t->timestamps();
                $t->unique(['banjar_id', 'id']);
                $t->unique(['banjar_id', 'slug']);
                $t->index(['banjar_id', 'status', 'published_at']);
            });
        }
        Schema::create('gallery_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained();
            $t->unsignedBigInteger('gallery_id');
            $t->unsignedBigInteger('media_id');
            $t->string('caption')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->foreign(['banjar_id', 'gallery_id'])->references(['banjar_id', 'id'])->on('galleries')->cascadeOnDelete();
            $t->foreign(['banjar_id', 'media_id'])->references(['banjar_id', 'id'])->on('media_files');
            $t->unique(['gallery_id', 'media_id']);
        });
    }

    public function down(): void
    {
        foreach (['gallery_items', 'galleries', 'public_documents', 'service_definitions', 'public_officials', 'news_posts', 'announcements', 'activities', 'website_profiles', 'media_files'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

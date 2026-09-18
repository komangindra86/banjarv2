<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function owned(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('banjar_id')->constrained()->restrictOnDelete();
        $t->unique(['banjar_id', 'id']);
        $t->timestamps();
    }

    private function ref(Blueprint $t, string $column, string $table): void
    {
        $t->foreign(['banjar_id', $column])->references(['banjar_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $this->owned($t);
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->char('endpoint_hash', 64);
            $t->text('endpoint_encrypted');
            $t->string('p256dh_key', 255);
            $t->string('auth_key', 255);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->unique(['banjar_id', 'endpoint_hash']);
            $t->index(['banjar_id', 'user_id']);
        });
        Schema::create('notifications', function (Blueprint $t) {
            $this->owned($t);
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('type', 60);
            $t->string('title', 150);
            $t->string('body', 500);
            $t->string('url', 255)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->index(['banjar_id', 'user_id', 'read_at']);
        });
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('notification_id');
            $this->ref($t, 'notification_id', 'notifications');
            $t->unsignedBigInteger('subscription_id');
            $this->ref($t, 'subscription_id', 'push_subscriptions');
            $t->enum('channel', ['WEB_PUSH'])->default('WEB_PUSH');
            $t->enum('status', ['PENDING', 'SENT', 'FAILED', 'EXPIRED'])->default('PENDING');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('last_error', 500)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unique(['banjar_id', 'notification_id', 'subscription_id'], 'delivery_one_per_subscription');
            $t->index(['banjar_id', 'status']);
        });
        DB::statement('ALTER TABLE notification_deliveries ADD CONSTRAINT delivery_attempts_bounded CHECK (attempts <= 10)');
    }

    public function down(): void
    {
        foreach (['notification_deliveries', 'notifications', 'push_subscriptions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

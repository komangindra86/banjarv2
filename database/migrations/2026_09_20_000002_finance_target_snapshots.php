<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    private function reference(Blueprint $t, string $column, string $table): void
    {
        $t->foreign(['banjar_id', $column])->references(['banjar_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::table('billing_events', function (Blueprint $t) {
            // Sasaran (KK) dan izin unit aktor dibekukan saat event dibuat, agar perubahan
            // keanggotaan/status/unit berikutnya tidak diam-diam mengubah siapa yang ditagih.
            $t->json('scope_unit_ids')->nullable()->after('unit_id');
            $t->unsignedInteger('target_kk_count')->nullable()->after('scope_unit_ids');
            $t->unsignedBigInteger('target_total_amount')->nullable()->after('target_kk_count');
            $t->timestamp('target_snapshot_at')->nullable()->after('target_total_amount');
            $t->timestamp('voided_at')->nullable()->after('void_reason');
        });
        Schema::create('billing_event_targets', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('billing_event_id');
            $t->unsignedBigInteger('household_id');
            $this->reference($t, 'billing_event_id', 'billing_events');
            $this->reference($t, 'household_id', 'households');
            $t->unique(['banjar_id', 'billing_event_id', 'household_id'], 'target_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_event_targets');
        Schema::table('billing_events', function (Blueprint $t) {
            $t->dropColumn(['scope_unit_ids', 'target_kk_count', 'target_total_amount', 'target_snapshot_at', 'voided_at']);
        });
    }
};

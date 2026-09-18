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

    private function reference(Blueprint $t, string $column, string $table): void
    {
        $t->foreign(['banjar_id', $column])->references(['banjar_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('finance_reports', function (Blueprint $t) {
            $this->owned($t);
            $t->date('period_from');
            $t->date('period_to');
            $t->string('sangkep_title', 200);
            $t->date('sangkep_date')->nullable();
            $t->unsignedBigInteger('previous_report_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('superseded_by_id')->nullable();
            $t->enum('status', ['DRAFT', 'FINALIZED'])->default('DRAFT');
            $t->enum('opening_balance_source', ['PREVIOUS_REPORT', 'MANUAL']);
            $t->unsignedBigInteger('opening_balance')->default(0);
            $t->text('opening_balance_note')->nullable();
            $t->text('reason_for_revision')->nullable();
            $t->string('signer_treasurer_name', 150)->nullable();
            $t->string('signer_kelian_name', 150)->nullable();
            $t->json('computed_snapshot')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('finalized_at')->nullable();
            $t->enum('pdf_status', ['PENDING', 'READY', 'FAILED'])->default('PENDING');
            $t->longText('pdf_encrypted')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->timestamp('generated_at')->nullable();
            $this->reference($t, 'previous_report_id', 'finance_reports');
            $this->reference($t, 'superseded_by_id', 'finance_reports');
            $t->index(['banjar_id', 'status']);
            $t->index(['banjar_id', 'period_to']);
        });
        DB::statement('ALTER TABLE finance_reports ADD CONSTRAINT report_period CHECK (period_to >= period_from)');
        DB::statement("ALTER TABLE finance_reports ADD CONSTRAINT report_opening_balance CHECK ((opening_balance_source = 'MANUAL' AND opening_balance_note IS NOT NULL) OR (opening_balance_source = 'PREVIOUS_REPORT' AND previous_report_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_reports');
    }
};

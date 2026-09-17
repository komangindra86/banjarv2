<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_definitions', function (Blueprint $t) {
            $t->boolean('letters_enabled')->default(false);
            $t->boolean('letter_requires_approval')->default(true);
            $t->text('letter_statement')->nullable();
            $t->unsignedInteger('letter_version')->default(1);
        });
        Schema::create('letter_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained();
            $t->unique(['banjar_id', 'id']);
            $t->unsignedBigInteger('service_definition_id');
            $t->unsignedBigInteger('krama_id');
            $t->unsignedBigInteger('household_id');
            $t->foreign(['banjar_id', 'service_definition_id'])->references(['banjar_id', 'id'])->on('service_definitions');
            $t->foreign(['banjar_id', 'krama_id'])->references(['banjar_id', 'id'])->on('krama');
            $t->foreign(['banjar_id', 'household_id'])->references(['banjar_id', 'id'])->on('households');
            $t->unsignedInteger('person_version');
            $t->unsignedInteger('household_version');
            $t->foreignId('requester_id')->constrained('users');
            $t->uuid('submission_key');
            $t->char('submission_hash', 64);
            $t->unique(['banjar_id', 'requester_id', 'submission_key'], 'letter_submission_unique');
            $t->text('identity_snapshot');
            $t->text('service_snapshot');
            $t->text('purpose');
            $t->boolean('requires_approval');
            $t->enum('status', ['SUBMITTED', 'VERIFIED', 'ISSUED', 'REJECTED', 'CANCELLED', 'CONFLICT'])->default('SUBMITTED');
            foreach (['verifier', 'approver'] as $stage) {
                $t->foreignId($stage.'_id')->nullable()->constrained('users');
                $t->timestamp($stage.'_at')->nullable();
            }
            $t->timestamps();
            $t->index(['banjar_id', 'status', 'id']);
        });
        Schema::create('letter_request_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained();
            $t->unsignedBigInteger('request_id');
            $t->foreign(['banjar_id', 'request_id'])->references(['banjar_id', 'id'])->on('letter_requests');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('status', 20);
            $t->text('note')->nullable();
            $t->timestamp('created_at');
            $t->index(['banjar_id', 'request_id']);
        });
        Schema::create('letter_sequences', function (Blueprint $t) {
            $t->foreignId('banjar_id')->constrained();
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number')->default(0);
            $t->primary(['banjar_id', 'year']);
        });
        Schema::create('issued_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained();
            $t->unsignedBigInteger('request_id');
            $t->foreign(['banjar_id', 'request_id'])->references(['banjar_id', 'id'])->on('letter_requests');
            $t->unique(['banjar_id', 'request_id']);
            $t->string('number', 80);
            $t->unique(['banjar_id', 'number']);
            $t->text('snapshot');
            $t->enum('pdf_status', ['PENDING', 'READY', 'FAILED'])->default('PENDING');
            $t->longText('pdf_encrypted')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->timestamp('issued_at');
            $t->timestamp('generated_at')->nullable();
        });
        DB::statement("ALTER TABLE issued_documents ADD CONSTRAINT issued_pdf_ready CHECK ((pdf_status = 'READY' AND pdf_encrypted IS NOT NULL AND sha256 IS NOT NULL AND generated_at IS NOT NULL) OR (pdf_status <> 'READY' AND pdf_encrypted IS NULL AND sha256 IS NULL AND generated_at IS NULL))");
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        foreach (['issued_documents', 'letter_sequences', 'letter_request_steps', 'letter_requests'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('service_definitions', fn (Blueprint $t) => $t->dropColumn(['letters_enabled', 'letter_requires_approval', 'letter_statement', 'letter_version']));
    }
};

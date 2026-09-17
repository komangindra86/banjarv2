<?php

use Database\Seeders\RoleSeeder;
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
        // Phase 2 activities are upgraded in place: one activity entity for agenda, paruman, ngayah and attendance.
        Schema::table('activities', function (Blueprint $t) {
            $t->enum('kind', ['GENERAL', 'PARUMAN', 'NGAYAH', 'GOTONG_ROYONG', 'UPACARA', 'RAPAT'])->default('GENERAL')->after('location');
            $t->enum('visibility', ['PUBLIC', 'INTERNAL'])->default('PUBLIC')->after('kind');
            $t->enum('attendance_basis', ['HOUSEHOLD', 'PERSON'])->nullable()->after('visibility');
            $t->boolean('allow_representative')->default(true)->after('attendance_basis');
            $t->enum('attendance_status', ['NONE', 'DRAFT', 'VERIFIED'])->default('NONE')->after('allow_representative');
            $t->timestamp('participants_generated_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('dedosan_billing_event_id')->nullable();
            $this->ref($t, 'dedosan_billing_event_id', 'billing_events');
            $t->unique(['banjar_id', 'dedosan_billing_event_id'], 'activity_one_dedosan_event');
        });
        Schema::create('activity_targets', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('activity_id');
            $this->ref($t, 'activity_id', 'activities');
            $t->enum('target_type', ['ALL_HOUSEHOLDS', 'ALL_KRAMA', 'ORGANIZATIONAL_UNIT', 'ROLE', 'SELECTED_KRAMA']);
            $t->unsignedBigInteger('unit_id')->nullable();
            $this->ref($t, 'unit_id', 'organizational_units');
            $t->boolean('include_descendants')->default(false);
            $t->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $t->unsignedBigInteger('krama_id')->nullable();
            $this->ref($t, 'krama_id', 'krama');
            $t->string('target_key', 60);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['banjar_id', 'activity_id', 'target_key'], 'activity_target_unique');
        });
        Schema::create('activity_participants', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('activity_id');
            $this->ref($t, 'activity_id', 'activities');
            $t->enum('basis', ['HOUSEHOLD', 'PERSON']);
            $t->unsignedBigInteger('household_id');
            $this->ref($t, 'household_id', 'households');
            $t->string('household_code', 40);
            $t->unsignedBigInteger('krama_id')->nullable();
            $this->ref($t, 'krama_id', 'krama');
            $t->unsignedBigInteger('household_slot')->nullable()->storedAs("IF(basis = 'HOUSEHOLD', household_id, NULL)");
            $t->unique(['banjar_id', 'activity_id', 'household_slot'], 'participant_one_household');
            $t->unique(['banjar_id', 'activity_id', 'krama_id'], 'participant_one_person');
            $t->enum('status', ['BELUM_DIVERIFIKASI', 'HADIR', 'IZIN', 'SAKIT', 'TIDAK_HADIR'])->default('BELUM_DIVERIFIKASI');
            $t->unsignedBigInteger('attending_krama_id')->nullable();
            $this->ref($t, 'attending_krama_id', 'krama');
            $t->enum('source', ['STAFF', 'SELF'])->nullable();
            $t->string('note', 500)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at')->nullable();
            $t->index(['banjar_id', 'activity_id', 'status']);
        });
        Schema::create('dedosan_candidates', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('activity_id');
            $this->ref($t, 'activity_id', 'activities');
            $t->unsignedBigInteger('household_id');
            $this->ref($t, 'household_id', 'households');
            $t->string('household_code', 40);
            $t->unsignedSmallInteger('absent_count')->default(1);
            $t->unsignedBigInteger('suggested_krama_id')->nullable();
            $this->ref($t, 'suggested_krama_id', 'krama');
            $t->unsignedBigInteger('responsible_krama_id')->nullable();
            $this->ref($t, 'responsible_krama_id', 'krama');
            $t->enum('status', ['PENDING', 'SELECTED', 'DISMISSED', 'BILLED'])->default('PENDING');
            $t->string('note', 500)->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->unsignedBigInteger('billing_event_id')->nullable();
            $this->ref($t, 'billing_event_id', 'billing_events');
            // One KK can only ever be a single dedosan candidate for an activity.
            $t->unique(['banjar_id', 'activity_id', 'household_id'], 'candidate_one_per_household');
            $t->index(['banjar_id', 'activity_id', 'status']);
        });
        Schema::create('activity_minutes', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('activity_id');
            $this->ref($t, 'activity_id', 'activities');
            $t->unique(['banjar_id', 'activity_id']);
            $t->text('agenda')->nullable();
            $t->mediumText('minutes')->nullable();
            $t->enum('status', ['DRAFT', 'FINAL'])->default('DRAFT');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('finalized_at')->nullable();
        });
        Schema::create('activity_decisions', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('activity_id');
            $this->ref($t, 'activity_id', 'activities');
            $t->unsignedSmallInteger('sort_order');
            $t->text('body');
            $t->unique(['banjar_id', 'activity_id', 'sort_order']);
        });
        Schema::create('activity_attachments', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('activity_id');
            $this->ref($t, 'activity_id', 'activities');
            $t->unsignedBigInteger('media_id');
            $this->ref($t, 'media_id', 'media_files');
            $t->unique(['banjar_id', 'activity_id', 'media_id']);
        });
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activity_attendance_basis CHECK (attendance_status = 'NONE' OR attendance_basis IS NOT NULL)");
        DB::statement("ALTER TABLE activity_targets ADD CONSTRAINT activity_target_shape CHECK ((target_type IN ('ALL_HOUSEHOLDS','ALL_KRAMA') AND unit_id IS NULL AND role_id IS NULL AND krama_id IS NULL) OR (target_type = 'ORGANIZATIONAL_UNIT' AND unit_id IS NOT NULL AND role_id IS NULL AND krama_id IS NULL) OR (target_type = 'ROLE' AND role_id IS NOT NULL AND unit_id IS NULL AND krama_id IS NULL) OR (target_type = 'SELECTED_KRAMA' AND krama_id IS NOT NULL AND unit_id IS NULL AND role_id IS NULL))");
        DB::statement("ALTER TABLE activity_participants ADD CONSTRAINT participant_basis_shape CHECK ((basis = 'PERSON' AND krama_id IS NOT NULL AND attending_krama_id IS NULL) OR (basis = 'HOUSEHOLD' AND krama_id IS NULL))");
        DB::statement("ALTER TABLE activity_participants ADD CONSTRAINT participant_attending_only_present CHECK (attending_krama_id IS NULL OR status = 'HADIR')");
        DB::statement("ALTER TABLE dedosan_candidates ADD CONSTRAINT candidate_decision_shape CHECK ((status = 'PENDING' AND responsible_krama_id IS NULL AND billing_event_id IS NULL) OR (status = 'SELECTED' AND responsible_krama_id IS NOT NULL AND billing_event_id IS NULL) OR (status = 'DISMISSED' AND responsible_krama_id IS NULL AND billing_event_id IS NULL AND note IS NOT NULL) OR (status = 'BILLED' AND responsible_krama_id IS NOT NULL AND billing_event_id IS NOT NULL))");
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        foreach (['activity_attachments', 'activity_decisions', 'activity_minutes', 'dedosan_candidates', 'activity_participants', 'activity_targets'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE activities DROP CHECK activity_attendance_basis');
        Schema::table('activities', function (Blueprint $t) {
            $t->dropForeign(['banjar_id', 'dedosan_billing_event_id']);
            $t->dropUnique('activity_one_dedosan_event');
            $t->dropForeign(['verified_by']);
            $t->dropColumn(['kind', 'visibility', 'attendance_basis', 'allow_representative', 'attendance_status', 'participants_generated_at', 'verified_by', 'verified_at', 'dedosan_billing_event_id']);
        });
        $codes = ['activity.view', 'attendance.record', 'attendance.verify', 'activity.self'];
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('code', $codes)->pluck('id'))->delete();
        DB::table('permissions')->whereIn('code', $codes)->delete();
    }
};

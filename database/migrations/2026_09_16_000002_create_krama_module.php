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

    private function reference(Blueprint $t, string $column, string $table): void
    {
        $t->foreign(['banjar_id', $column])->references(['banjar_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('organizational_units', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('type', 40);
            $t->string('name', 150);
            $t->boolean('is_active')->default(true);
            $this->reference($t, 'parent_id', 'organizational_units');
        });
        Schema::create('households', function (Blueprint $t) {
            $this->owned($t);
            $t->string('code', 40);
            $t->text('family_number')->nullable();
            $t->string('address', 1000)->nullable();
            $t->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $t->unsignedBigInteger('head_krama_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unique(['banjar_id', 'code']);
        });
        Schema::create('krama', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('household_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('name', 150);
            $t->text('nik')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('phone', 30)->nullable();
            $t->enum('status', ['ACTIVE', 'INACTIVE', 'MOVED', 'DECEASED'])->default('ACTIVE');
            $t->unsignedInteger('version')->default(1);
            $this->reference($t, 'household_id', 'households');
            $t->foreign(['banjar_id', 'user_id'])->references(['banjar_id', 'user_id'])->on('banjar_user_memberships')->restrictOnDelete();
            $t->unique(['banjar_id', 'user_id']);
            $t->unique(['banjar_id', 'household_id', 'id'], 'krama_household_member');
            $t->index(['banjar_id', 'name']);
        });
        Schema::table('households', function (Blueprint $t) {
            $t->foreign(['banjar_id', 'id', 'head_krama_id'], 'household_head_member')->references(['banjar_id', 'household_id', 'id'])->on('krama')->restrictOnDelete();
        });
        foreach (['household' => 'households', 'krama' => 'krama'] as $entity => $table) {
            Schema::create($entity.'_unit_memberships', function (Blueprint $t) use ($entity, $table) {
                $this->owned($t);
                $t->unsignedBigInteger($entity.'_id');
                $t->unsignedBigInteger('unit_id');
                $this->reference($t, $entity.'_id', $table);
                $this->reference($t, 'unit_id', 'organizational_units');
                $t->unique(['banjar_id', $entity.'_id', 'unit_id'], $entity.'_unit_unique');
            });
        }
        DB::statement("ALTER TABLE tenant_role_assignments MODIFY scope_type ENUM('BANJAR','ORGANIZATIONAL_UNIT') NOT NULL DEFAULT 'BANJAR'");
        Schema::table('tenant_role_assignments', function (Blueprint $t) {
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->boolean('include_descendants')->default(false);
            $this->reference($t, 'unit_id', 'organizational_units');
        });
        DB::statement("ALTER TABLE tenant_role_assignments ADD CONSTRAINT assignment_unit_check CHECK ((scope_type = 'BANJAR' AND unit_id IS NULL AND include_descendants = 0) OR (scope_type = 'ORGANIZATIONAL_UNIT' AND unit_id IS NOT NULL))");
        Schema::table('banjar_settings', fn (Blueprint $t) => $t->boolean('krama_changes_require_approval')->default(false));
        Schema::create('krama_change_requests', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('krama_id');
            $this->reference($t, 'krama_id', 'krama');
            $t->unsignedInteger('base_version');
            $t->boolean('requires_approval');
            $t->enum('status', ['SUBMITTED', 'VERIFIED', 'APPLIED', 'REJECTED', 'CONFLICT'])->default('SUBMITTED');
            foreach (['requester', 'verifier', 'approver'] as $actor) {
                $t->foreignId($actor.'_id')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp($actor.'_at')->nullable();
            }
            $t->text('reason');
            $t->text('review_note')->nullable();
            $t->index(['banjar_id', 'status']);
        });
        Schema::create('krama_change_items', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('request_id');
            $this->reference($t, 'request_id', 'krama_change_requests');
            $t->string('field', 40);
            $t->text('old_value');
            $t->text('new_value');
            $t->unique(['banjar_id', 'request_id', 'field'], 'change_field_unique');
        });
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('krama_change_items');
        Schema::dropIfExists('krama_change_requests');
        Schema::table('banjar_settings', fn (Blueprint $t) => $t->dropColumn('krama_changes_require_approval'));
        DB::statement('ALTER TABLE tenant_role_assignments DROP CHECK assignment_unit_check');
        DB::table('tenant_role_assignments')->where('scope_type', 'ORGANIZATIONAL_UNIT')->delete();
        Schema::table('tenant_role_assignments', function (Blueprint $t) {
            $t->dropForeign(['banjar_id', 'unit_id']);
            $t->dropColumn(['unit_id', 'include_descendants']);
        });
        DB::statement("ALTER TABLE tenant_role_assignments MODIFY scope_type ENUM('BANJAR') NOT NULL DEFAULT 'BANJAR'");
        Schema::dropIfExists('krama_unit_memberships');
        Schema::dropIfExists('household_unit_memberships');
        Schema::table('households', fn (Blueprint $t) => $t->dropForeign('household_head_member'));
        Schema::dropIfExists('krama');
        Schema::dropIfExists('households');
        Schema::dropIfExists('organizational_units');
    }
};

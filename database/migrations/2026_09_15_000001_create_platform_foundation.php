<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE'));
        Schema::create('banjars', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('slug', 63)->unique();
            $t->enum('status', ['DRAFT', 'ACTIVE', 'SUSPENDED', 'ARCHIVED'])->default('DRAFT');
            $t->unsignedBigInteger('primary_domain_id')->nullable();
            $t->timestamps();
        });
        Schema::create('tenant_domains', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained()->restrictOnDelete();
            $t->string('domain', 253)->unique();
            $t->enum('verification_status', ['PENDING', 'VERIFIED', 'REJECTED'])->default('PENDING');
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['banjar_id', 'id']);
        });
        Schema::table('banjars', function (Blueprint $t) {
            $t->foreign(['id', 'primary_domain_id'])->references(['banjar_id', 'id'])->on('tenant_domains')->restrictOnDelete();
        });
        Schema::create('banjar_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->unique()->constrained()->restrictOnDelete();
            $t->string('template_code', 30)->default('template-01');
            $t->string('accent_color', 7)->default('#176b54');
            $t->boolean('payout_requires_kelian_approval')->default(false);
            $t->unsignedSmallInteger('settlement_hold_days')->nullable();
            $t->timestamps();
        });
        Schema::create('banjar_modules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained()->restrictOnDelete();
            $t->string('code', 30);
            $t->enum('status', ['INACTIVE', 'BASIC', 'ACTIVE'])->default('INACTIVE');
            $t->unique(['banjar_id', 'code']);
            $t->timestamps();
        });
        Schema::create('banjar_user_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $t->unique(['banjar_id', 'user_id']);
            $t->unique(['banjar_id', 'id']);
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 100);
            $t->enum('scope', ['PLATFORM', 'TENANT']);
            $t->unique(['id', 'scope']);
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80)->unique();
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->foreignId('permission_id')->constrained()->restrictOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('platform_role_assignments', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('role_id');
            $t->enum('role_scope', ['PLATFORM', 'TENANT'])->default('PLATFORM');
            $t->foreign(['role_id', 'role_scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $t->primary(['user_id', 'role_id']);
        });
        Schema::create('tenant_role_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('membership_id');
            $t->unsignedBigInteger('role_id');
            $t->enum('role_scope', ['PLATFORM', 'TENANT'])->default('TENANT');
            $t->enum('scope_type', ['BANJAR'])->default('BANJAR');
            $t->foreign(['role_id', 'role_scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $t->foreign(['banjar_id', 'membership_id'])->references(['banjar_id', 'id'])->on('banjar_user_memberships')->restrictOnDelete();
            $t->unique(['banjar_id', 'membership_id', 'role_id'], 'tenant_role_unique');
        });
        DB::statement("ALTER TABLE platform_role_assignments ADD CONSTRAINT platform_scope_check CHECK (role_scope = 'PLATFORM')");
        DB::statement("ALTER TABLE tenant_role_assignments ADD CONSTRAINT tenant_scope_check CHECK (role_scope = 'TENANT')");
        DB::statement("ALTER TABLE banjar_modules ADD CONSTRAINT module_basic_check CHECK (status <> 'BASIC' OR code = 'ACTIVITY')");
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('banjar_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 80);
            $t->string('entity_type', 80);
            $t->string('entity_id', 80)->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->uuid('request_id');
            $t->timestamp('created_at');
            $t->index(['banjar_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'tenant_role_assignments', 'platform_role_assignments', 'role_permissions', 'permissions', 'roles', 'banjar_user_memberships', 'banjar_modules', 'banjar_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('banjars', fn (Blueprint $t) => $t->dropForeign(['id', 'primary_domain_id']));
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('banjars');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('status'));
    }
};

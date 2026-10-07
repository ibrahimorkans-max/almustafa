<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('module', 32)->index();
            $table->string('description');
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL = global role (only Super Admin).
            $table->foreignUuid('entity_id')->nullable()->constrained('entities');
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('is_system')->default(true);
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('mfa_required')->default(false);
            $table->unsignedInteger('session_max_minutes')->default(720);
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles');
            $table->string('permission_code', 64);
            $table->foreign('permission_code')->references('code')->on('permissions');
            $table->foreignUuid('entity_id')->nullable()->constrained('entities');
            $table->uuid('granted_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->primary(['role_id', 'permission_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};

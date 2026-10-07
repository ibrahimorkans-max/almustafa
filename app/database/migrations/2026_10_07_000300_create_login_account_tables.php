<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('person_id')->constrained('persons');
            $table->foreignUuid('role_id')->constrained('roles');
            // Stored lower-case; unique across all entities.
            $table->string('username', 64)->unique();
            $table->string('password');
            $table->string('status', 16)->default('active');
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->unsignedBigInteger('mfa_last_timestep')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['person_id', 'entity_id']);
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('login_account_id')->constrained('login_accounts');
            $table->char('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['login_account_id', 'code_hash']);
        });

        Schema::create('ip_allowlist', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('login_account_id')->constrained('login_accounts');
            $table->string('cidr', 64);
            $table->string('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_allowlist');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('login_accounts');
    }
};

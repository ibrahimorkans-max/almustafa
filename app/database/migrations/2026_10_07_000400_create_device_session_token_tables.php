<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('login_account_id')->constrained('login_accounts');
            // Client-generated identifier (browser cookie, app install id, agent id).
            $table->string('device_uid', 100);
            $table->string('name', 120)->nullable();
            $table->string('platform', 20);
            // active | revoked | wipe_pending | wiped
            $table->string('status', 20)->default('active');
            $table->timestamp('trusted_at')->nullable();
            $table->uuid('trusted_by')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('wipe_requested_at')->nullable();
            $table->uuid('wipe_requested_by')->nullable();
            $table->timestamp('wiped_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['login_account_id', 'device_uid']);
        });

        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('login_account_id')->constrained('login_accounts');
            $table->foreignUuid('device_id')->nullable()->constrained('devices');
            $table->string('channel', 8); // web | api
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('mfa_passed_at')->nullable();
            $table->dateTime('expires_at'); // DATETIME: MySQL TIMESTAMP needs a default and ends in 2038
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestamps();
            $table->index(['login_account_id', 'revoked_at']);
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('auth_session_id')->constrained('auth_sessions');
            $table->foreignUuid('device_id')->constrained('devices');
            $table->string('type', 8); // access | refresh
            $table->char('token_hash', 64)->unique();
            $table->uuid('parent_id')->nullable();
            $table->dateTime('expires_at'); // DATETIME: MySQL TIMESTAMP needs a default and ends in 2038
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('auth_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('devices');
    }
};

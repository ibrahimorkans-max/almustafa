<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installation-level security state (not tenant data, like backup_runs): emergency lockdowns and
 * maintenance windows. Every change is also in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_locks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 10);                       // system | entity
            $table->foreignUuid('entity_id')->nullable()->constrained('entities');
            $table->text('reason');
            $table->uuid('locked_by');
            $table->dateTime('locked_at', 3);
            $table->uuid('unlocked_by')->nullable();
            $table->dateTime('unlocked_at', 3)->nullable();
            $table->text('unlock_reason')->nullable();
            $table->index(['unlocked_at', 'scope']);
        });

        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('message', 255);
            $table->dateTime('starts_at', 3);
            $table->dateTime('ends_at', 3);
            $table->string('bypass_hash', 64);                 // sha256 of the secret bypass token
            $table->uuid('created_by');
            $table->dateTime('created_at', 3);
            $table->uuid('ended_by')->nullable();
            $table->dateTime('ended_at', 3)->nullable();
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
        Schema::dropIfExists('system_locks');
    }
};

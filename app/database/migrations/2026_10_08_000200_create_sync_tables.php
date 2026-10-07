<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every event a device pushed, with its outcome. event_id is the idempotency key.
        Schema::create('sync_events', function (Blueprint $table) {
            $table->uuid('id')->primary();              // = client event_id
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('device_id')->constrained('devices');
            $table->uuid('login_account_id');
            $table->unsignedBigInteger('client_seq');
            $table->dateTime('occurred_at', 3);        // device clock
            $table->dateTime('received_at', 3);
            $table->string('type', 64);
            $table->uuid('record_id')->nullable();
            $table->unsignedInteger('base_version')->nullable();
            $table->json('payload');
            // applied | conflict_client_won | conflict_server_won | needs_review | pending_online | rejected
            $table->string('status', 24);
            $table->json('result')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['device_id', 'client_seq']);
            $table->index(['entity_id', 'status']);
        });

        // Ordered change feed for pull. seq is gap-free and commit-ordered per entity.
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->unsignedBigInteger('seq');
            $table->string('type', 32);                // vehicle, party, …
            $table->uuid('record_id');
            $table->unsignedInteger('version');
            $table->string('op', 10);                  // upsert | archive
            $table->dateTime('changed_at', 3);
            $table->unique(['entity_id', 'seq']);
            $table->index(['entity_id', 'type', 'record_id']);
        });

        Schema::create('sync_change_heads', function (Blueprint $table) {
            $table->uuid('entity_id')->primary();
            $table->unsignedBigInteger('seq');
        });

        // Conflict log + supervisor review queue.
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->uuid('sync_event_id');
            $table->string('type', 32);
            $table->uuid('record_id')->nullable();
            // client_won | server_won | needs_review
            $table->string('resolution', 16);
            $table->string('reason', 64);
            $table->json('client_payload');
            $table->json('server_snapshot')->nullable();
            $table->string('review_status', 16)->nullable(); // open | applied | dismissed
            $table->uuid('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['entity_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_change_heads');
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('sync_events');
    }
};

<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phones and browsers a party turned notifications on for (Firebase Cloud Messaging): each party
 * message also arrives there as a notification. A token Firebase no longer knows is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_push_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->uuid('login_account_id')->nullable();
            $table->text('token');
            $table->char('token_hash', 64);
            $table->string('device', 160)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['party_id', 'token_hash']);
        });

        TenantTables::secure(['party_push_tokens']);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(['party_push_tokens']);
        Schema::dropIfExists('party_push_tokens');
    }
};

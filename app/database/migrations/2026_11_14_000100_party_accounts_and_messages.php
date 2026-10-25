<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Party accounts and messages to parties.
 *  - parties.messages_on: this party gets messages (on by default; the office can switch a party off);
 *  - party_messages: every message to a party — its login details, each money entry, a vehicle unloaded
 *    or weighed — with how it went: sent by the gateway, waiting to be sent by hand (no gateway yet), failed
 *    or skipped. A message carrying a password is kept without its text (redacted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->boolean('messages_on')->default(true);
        });

        Schema::create('party_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->string('event', 30);              // login | money | unloaded | weighed
            $table->string('channel', 12);            // sms | whatsapp
            $table->string('recipient', 20);
            $table->text('body')->nullable();         // null when it carried a password
            $table->boolean('redacted')->default(false);
            $table->string('status', 12);             // sent | manual | failed | skipped
            $table->string('error', 255)->nullable();
            $table->string('subject_type', 30)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['entity_id', 'status', 'created_at']);
            $table->index(['party_id', 'created_at']);
        });

        TenantTables::secure(['party_messages']);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(['party_messages']);
        Schema::dropIfExists('party_messages');
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('messages_on');
        });
    }
};

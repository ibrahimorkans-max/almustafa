<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What WhatsApp tells us through its webhook (Meta → Configure Webhooks): a message's progress (sent,
 * delivered, read, failed) and the messages people send to the business number. Append-only, one row per
 * event (Meta may send the same event again; it is kept once). The message id WhatsApp gave each message
 * we sent is kept on the party message and the statement share, so their delivery can be shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The mill it concerns; null when it could not be told (a message to the product's number from an unknown phone).
            $table->foreignUuid('entity_id')->nullable()->constrained('entities');
            $table->string('scope', 40);                      // platform | the mill whose own number it is
            $table->string('kind', 10);                       // status | message
            $table->string('wa_message_id', 190);
            $table->string('status', 12);                     // sent | delivered | read | failed; received (a message)
            $table->string('phone', 32)->nullable();          // the other side: recipient (status) or sender (message)
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            $table->string('type', 20)->nullable();           // text | image | button … (kind message)
            $table->text('body')->nullable();                 // the text of a message received
            $table->string('error', 300)->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('created_at', 3);
            $table->unique(['wa_message_id', 'kind', 'status']);
            $table->index(['entity_id', 'kind', 'occurred_at']);
        });
        TenantTables::secure(['whatsapp_events'], ['whatsapp_events']);

        Schema::table('party_messages', function (Blueprint $table) {
            $table->string('provider_message_id', 190)->nullable()->index();
            $table->string('delivery_status', 12)->nullable();   // delivered | read | failed (from the webhook)
            $table->dateTime('delivery_at')->nullable();
        });
        Schema::table('statement_shares', function (Blueprint $table) {
            $table->string('provider_message_id', 190)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('statement_shares', fn (Blueprint $table) => $table->dropColumn('provider_message_id'));
        Schema::table('party_messages', fn (Blueprint $table) => $table->dropColumn(['provider_message_id', 'delivery_status', 'delivery_at']));
        TenantTables::dropTriggers(['whatsapp_events']);
        Schema::dropIfExists('whatsapp_events');
    }
};

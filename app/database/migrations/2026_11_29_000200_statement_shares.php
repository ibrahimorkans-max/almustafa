<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a party statement is shared (WhatsApp, e-mail, a copied link) or printed / downloaded: who,
 * to whom, which period and filters, and what actually happened (handed to WhatsApp or the mail server,
 * or only a link prepared for sending by hand). Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->string('channel', 12);                   // whatsapp | email | link | print | pdf
            $table->string('recipient', 190)->nullable();
            $table->date('period_from');
            $table->date('period_to');
            $table->json('filters')->nullable();
            $table->string('subject', 190)->nullable();
            $table->string('status', 20);                     // accepted | prepared | sent | failed | done
            $table->string('detail', 300)->nullable();
            $table->dateTime('link_expires_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['entity_id', 'party_id', 'created_at']);
        });

        TenantTables::secure(['statement_shares'], ['statement_shares']);
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_shares');
    }
};

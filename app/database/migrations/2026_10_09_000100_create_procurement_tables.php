<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement: requirements → quotes (append-only revisions) → saudas (append-only events).
 * Quantities in kg (decimal 15,3), rates in paisa per maund (integer).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gap-free document numbers per entity, series and financial year.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('series', 10);
            $table->string('financial_year', 7); // 2026-27
            $table->unsignedInteger('last_number')->default(0);
            $table->primary(['entity_id', 'series', 'financial_year']);
        });

        Schema::create('paddy_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('product_id')->constrained('products');
            $table->decimal('quantity_kg', 15, 3);
            $table->unsignedBigInteger('target_rate_paise_per_maund')->nullable();
            $table->json('quality_spec')->nullable();     // {"moisture_max_bp":1700,...}
            $table->date('delivery_from')->nullable();
            $table->date('delivery_to')->nullable();
            $table->string('status', 16)->default('draft'); // draft | open | closed
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
        });

        Schema::create('requirement_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('requirement_id')->constrained('paddy_requirements');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->string('channel', 16)->default('portal');
            $table->dateTime('notified_at');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['requirement_id', 'party_id']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('requirement_id')->nullable()->constrained('paddy_requirements');
            $table->foreignUuid('party_id')->constrained('parties');          // seller
            $table->foreignUuid('broker_party_id')->nullable()->constrained('parties');
            $table->foreignUuid('product_id')->constrained('products');
            // submitted | countered | accepted | rejected | withdrawn | expired
            $table->string('status', 16)->default('submitted');
            $table->unsignedInteger('current_revision')->default(1);
            $table->decimal('quantity_kg', 15, 3);                            // latest offer
            $table->unsignedBigInteger('rate_paise_per_maund');
            $table->string('last_offer_by', 8);                               // mill | seller
            $table->dateTime('valid_until')->nullable();
            $table->uuid('sauda_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
        });

        Schema::create('quote_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('quote_id')->constrained('quotes');
            $table->unsignedInteger('revision');
            $table->string('offered_by', 8);                                  // mill | seller
            $table->decimal('quantity_kg', 15, 3);
            $table->unsignedBigInteger('rate_paise_per_maund');
            $table->json('terms')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->unique(['quote_id', 'revision']);
        });

        Schema::create('saudas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->string('direction', 8)->default('purchase');              // purchase | sale
            // draft | pending_approval | confirmed | in_delivery | completed | cancelled | expired
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->foreignUuid('broker_party_id')->nullable()->constrained('parties');
            $table->foreignUuid('product_id')->constrained('products');
            $table->decimal('quantity_kg', 15, 3);
            $table->unsignedInteger('tolerance_bp')->default(500);            // ± allowed delivery deviation
            $table->unsignedBigInteger('rate_paise_per_maund');
            $table->unsignedBigInteger('brokerage_paise_per_maund')->default(0);
            $table->json('quality_terms')->nullable();
            $table->date('delivery_from')->nullable();
            $table->date('delivery_to')->nullable();
            $table->unsignedSmallInteger('payment_days')->default(0);
            $table->foreignUuid('quote_id')->nullable()->constrained('quotes');
            $table->foreignUuid('requirement_id')->nullable()->constrained('paddy_requirements');
            $table->decimal('delivered_kg', 15, 3)->default(0);               // projection of delivery events
            $table->uuid('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('closed_at')->nullable();                         // cancelled / expired
            $table->text('close_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('sauda_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('sauda_id')->constrained('saudas');
            // created | submitted | approved | rejected | delivery | completed | cancelled | expired
            $table->string('event', 20);
            $table->json('data')->nullable();
            $table->uuid('actor_login_account_id')->nullable();
            $table->dateTime('occurred_at', 3);
            $table->index(['sauda_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['sauda_events', 'saudas', 'quote_revisions', 'quotes', 'requirement_recipients', 'paddy_requirements', 'document_sequences'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

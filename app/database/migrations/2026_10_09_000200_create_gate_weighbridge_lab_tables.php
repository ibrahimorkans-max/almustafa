<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate, weighbridge and lab. Facts (gate events, weighments, lab tests) are append-only:
 * corrections are new rows that supersede, never edits. Percentages are basis points
 * (1700 = 17.00 %), weights kg decimal(15,3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->decimal('capacity_kg', 15, 3);
            $table->decimal('resolution_kg', 8, 3)->default(10);   // smallest step (e = d)
            $table->string('location', 120)->nullable();
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('scale_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('scale_id')->constrained('scales');
            $table->string('type', 20);                 // stamping (Legal Metrology) | calibration
            $table->string('certificate_no', 60);
            $table->string('issued_by', 160)->nullable();
            $table->date('issued_on');
            $table->date('valid_until');
            $table->char('file_sha256', 64)->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['scale_id', 'valid_until']);
        });

        // Content-addressed private files (weighbridge photos, certificates). Served only through authorised routes.
        Schema::create('stored_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->char('sha256', 64);
            $table->string('mime', 64);
            $table->unsignedInteger('size_bytes');
            $table->string('path');
            $table->uuid('device_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['entity_id', 'sha256']);
        });

        Schema::create('deduction_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('product_id')->constrained('products');
            // moisture | foreign_matter | broken | damaged | immature | chalky | bags
            $table->string('parameter', 20);
            // excess_weight: deduct (value - free) × factor % of net weight
            // rate_cut: cut factor paisa/maund per 1 % above free
            // per_bag: deduct factor grams per bag
            $table->string('method', 16);
            $table->unsignedInteger('free_limit_bp')->default(0);
            $table->unsignedInteger('factor')->default(10000);     // bp multiplier, paise, or grams
            $table->unsignedInteger('reject_above_bp')->nullable();
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'product_id', 'parameter']);
        });

        Schema::create('gate_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();                          // client UUID allowed (offline)
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40)->nullable();               // assigned on the server
            $table->string('direction', 8);                         // inbound | outbound
            $table->string('purpose', 20);                          // paddy_purchase | dispatch | other
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles');
            $table->string('registration_no', 16);
            $table->foreignUuid('driver_id')->nullable()->constrained('drivers');
            $table->string('driver_name', 120)->nullable();
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            $table->foreignUuid('sauda_id')->nullable()->constrained('saudas');
            $table->foreignUuid('product_id')->nullable()->constrained('products');
            $table->unsignedInteger('bag_count')->nullable();
            // projection of gate_events
            $table->string('status', 20)->default('arrived');
            $table->json('flags')->nullable();
            $table->decimal('gross_kg', 15, 3)->nullable();
            $table->decimal('tare_kg', 15, 3)->nullable();
            $table->decimal('net_kg', 15, 3)->nullable();
            $table->decimal('deduction_kg', 15, 3)->nullable();
            $table->decimal('payable_kg', 15, 3)->nullable();
            $table->unsignedBigInteger('rate_cut_paise_per_maund')->nullable();
            $table->dateTime('arrived_at', 3);
            $table->dateTime('exited_at', 3)->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->index(['entity_id', 'status']);
            $table->index(['entity_id', 'registration_no']);
        });

        Schema::create('gate_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('gate_entry_id')->constrained('gate_entries');
            // arrived | gross_weighed | tare_weighed | lab_accepted | lab_rejected | unloaded | loaded |
            // slip_issued | exited | cancelled
            $table->string('event', 20);
            $table->json('data')->nullable();
            $table->string('anomaly')->nullable();                  // e.g. out of expected order (offline)
            $table->uuid('actor_login_account_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->dateTime('occurred_at', 3);
            $table->dateTime('recorded_at', 3);
            $table->index(['gate_entry_id', 'occurred_at']);
        });

        Schema::create('weighments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('gate_entry_id')->constrained('gate_entries');
            $table->string('kind', 8);                              // gross | tare
            $table->decimal('weight_kg', 15, 3);
            $table->foreignUuid('scale_id')->nullable()->constrained('scales');
            $table->string('source', 16);                           // indicator | manual_override
            $table->json('readings')->nullable();                   // stable-window evidence from the indicator
            $table->char('photo_sha256', 64)->nullable();
            $table->uuid('supersedes_id')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('approval_request_id')->nullable();
            $table->json('flags')->nullable();
            $table->uuid('device_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('captured_at', 3);
            $table->dateTime('created_at', 3);
            $table->index(['gate_entry_id', 'kind']);
        });

        Schema::create('lab_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('gate_entry_id')->constrained('gate_entries');
            $table->string('sample_code', 40)->nullable();
            $table->unsignedInteger('moisture_bp')->nullable();
            $table->unsignedInteger('foreign_matter_bp')->nullable();
            $table->unsignedInteger('broken_bp')->nullable();
            $table->unsignedInteger('damaged_bp')->nullable();
            $table->unsignedInteger('immature_bp')->nullable();
            $table->unsignedInteger('chalky_bp')->nullable();
            $table->string('grade', 20)->nullable();
            $table->text('remarks')->nullable();
            $table->uuid('supersedes_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('tested_at', 3);
            $table->dateTime('created_at', 3);
            $table->index('gate_entry_id');
        });
    }

    public function down(): void
    {
        foreach (['lab_tests', 'weighments', 'gate_events', 'gate_entries', 'deduction_rules', 'stored_files', 'scale_certificates', 'scales'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

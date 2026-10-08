<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations data (not tenant data, so no RLS): heartbeats of the scheduler / queue worker and the
 * history of backups and restore drills. Also indexes for the busiest lists and reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->string('name', 40)->primary();       // scheduler | queue
            $table->dateTime('beat_at', 3);
            $table->json('data')->nullable();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 12);                   // backup | drill
            $table->string('status', 12);                 // ok | failed
            $table->string('file', 255)->nullable();      // archive name (no path secrets)
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->boolean('offsite')->default(false);
            $table->json('details')->nullable();          // manifest summary, verifier results, error
            $table->dateTime('started_at', 3);
            $table->dateTime('finished_at', 3)->nullable();
            $table->index(['kind', 'started_at']);
        });

        Schema::table('gate_entries', function (Blueprint $table) {
            $table->index(['registration_no', 'arrived_at']);   // vehicle history (tare check, load outliers)
        });
        Schema::table('sales_bills', function (Blueprint $table) {
            $table->index(['party_id', 'status']);
            $table->index(['entity_id', 'bill_date']);
        });
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->index(['party_id', 'status']);
            $table->index(['entity_id', 'bill_date']);
        });
        Schema::table('saudas', function (Blueprint $table) {
            $table->index(['product_id', 'direction', 'approved_at']);
        });
        Schema::table('alerts', function (Blueprint $table) {
            $table->index(['type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', fn (Blueprint $t) => $t->dropIndex(['type', 'subject_id']));
        Schema::table('saudas', fn (Blueprint $t) => $t->dropIndex(['product_id', 'direction', 'approved_at']));
        Schema::table('purchase_bills', function (Blueprint $t) {
            $t->dropIndex(['party_id', 'status']);
            $t->dropIndex(['entity_id', 'bill_date']);
        });
        Schema::table('sales_bills', function (Blueprint $t) {
            $t->dropIndex(['party_id', 'status']);
            $t->dropIndex(['entity_id', 'bill_date']);
        });
        Schema::table('gate_entries', fn (Blueprint $t) => $t->dropIndex(['registration_no', 'arrived_at']));
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('system_heartbeats');
    }
};

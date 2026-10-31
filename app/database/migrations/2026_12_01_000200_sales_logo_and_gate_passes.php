<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second logo for selling (sales invoices, sales gate passes, dispatch orders, sale saudas) beside the mill's
 * logo for everything else; and the simple gate pass (Gate & weighbridge → Gate passes): party, vehicle, bags,
 * weight per bag, with a serial number and a verification code. Gate passes are append-only: a wrong one is
 * cancelled (a cancellation row), never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('sales_logo_path', 255)->nullable();
        });

        Schema::create('gate_passes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('serial', 30);                          // GP-000001, the mill's continuous series
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            $table->string('party_name', 160);                     // as printed (the party's name, or typed)
            $table->string('vehicle_no', 30);
            $table->foreignUuid('gate_entry_id')->nullable()->constrained('gate_entries');
            $table->unsignedInteger('bag_count');
            $table->decimal('kg_per_bag', 10, 3);
            $table->decimal('total_kg', 14, 3);
            $table->string('goods', 160)->nullable();
            $table->string('purpose', 10);                         // sale | other (which logo it carries)
            $table->string('remarks', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->unique(['entity_id', 'serial']);
            $table->index(['entity_id', 'created_at']);
        });
        Schema::create('gate_pass_cancellations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('gate_pass_id')->unique()->constrained('gate_passes');
            $table->string('reason', 300);
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
        });
        TenantTables::secure(['gate_passes', 'gate_pass_cancellations'], ['gate_passes', 'gate_pass_cancellations']);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(['gate_passes', 'gate_pass_cancellations']);
        Schema::dropIfExists('gate_pass_cancellations');
        Schema::dropIfExists('gate_passes');
        Schema::table('entities', fn (Blueprint $table) => $table->dropColumn('sales_logo_path'));
    }
};

<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labour contractors paid per vehicle (unloading paddy, loading dispatches):
 *  - contractor_rates: each contractor's rate per vehicle, per bag, per ton or per maund, optionally for one
 *    kind of vehicle, from an effective date;
 *  - gate_entries: the contractor who unloaded (or loaded) the vehicle, and the bill that paid for it, so a
 *    contractor's many vehicles are billed together and none twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->string('work', 12)->default('unloading');   // unloading | loading
            $table->string('basis', 10);                        // vehicle | bag | ton | maund
            $table->string('vehicle_type', 16)->nullable();     // truck, tractor … (empty: any)
            $table->unsignedBigInteger('rate_paise');
            $table->date('effective_from');
            $table->string('status', 12)->default('active');
            $table->string('note', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['entity_id', 'party_id', 'work']);
        });

        Schema::table('gate_entries', function (Blueprint $table) {
            $table->foreignUuid('contractor_party_id')->nullable()->constrained('parties');
            $table->string('contractor_work', 12)->nullable();  // unloading | loading
            $table->foreignUuid('contractor_bill_id')->nullable()->constrained('contractor_bills');
            $table->index(['entity_id', 'contractor_party_id', 'contractor_bill_id']);
        });

        TenantTables::secure(['contractor_rates']);
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropIndex(['entity_id', 'contractor_party_id', 'contractor_bill_id']);
            $table->dropConstrainedForeignId('contractor_bill_id');
            $table->dropConstrainedForeignId('contractor_party_id');
            $table->dropColumn('contractor_work');
        });
        Schema::dropIfExists('contractor_rates');
    }
};

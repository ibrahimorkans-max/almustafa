<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Small and unregistered vehicles at the gate: bullock carts, donkey and horse carts, rickshaws,
 * motorbikes, bicycles, hand carts. A visit records the kind of vehicle; one with no number plate gets
 * its own reference (NOPLATE + the gate entry number, never a real plate) and a description to know it by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->string('registration_no', 24)->change();
            $table->string('vehicle_type', 20)->nullable();
            $table->boolean('no_plate')->default(false);
            $table->string('vehicle_description', 160)->nullable();
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('vehicle_type', 20)->default('truck')->change();
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropColumn(['vehicle_type', 'no_plate', 'vehicle_description']);
        });
    }
};

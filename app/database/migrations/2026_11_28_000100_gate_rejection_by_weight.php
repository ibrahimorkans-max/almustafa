<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paddy rejected at unloading given by weight (kg) as well as by bags, and where it is at the empty
 * weighing: still on the vehicle (net = gross − empty weight is then already the paddy unloaded), or taken
 * off and kept aside (its weight is then subtracted from net, so net is still only the paddy accepted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->decimal('rejected_kg', 15, 3)->nullable();
            $table->boolean('rejected_off_vehicle')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', fn (Blueprint $table) => $table->dropColumn(['rejected_kg', 'rejected_off_vehicle']));
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paddy rejected while unloading: the rejected bags stay on (or go back onto) the vehicle, so its second
 * (empty) weight carries them and net = gross − that weight = only the paddy unloaded. The rejected bags
 * and the reason are kept; per-bag deductions count only the bags unloaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->unsignedInteger('rejected_bags')->nullable();
            $table->string('rejection_reason', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', fn (Blueprint $table) => $table->dropColumn(['rejected_bags', 'rejection_reason']));
    }
};

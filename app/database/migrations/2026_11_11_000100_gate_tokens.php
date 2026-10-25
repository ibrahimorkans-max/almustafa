<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens: each vehicle gets the next number of the day when it arrives, and is served in that order
 * (first come, first served) at the lab and the weighbridge. Numbers start again at 1 every day (PKT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->unsignedInteger('token_no')->nullable();
            $table->date('token_date')->nullable();
            $table->unique(['entity_id', 'token_date', 'token_no']);
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'token_date', 'token_no']);
            $table->dropColumn(['token_no', 'token_date']);
        });
    }
};

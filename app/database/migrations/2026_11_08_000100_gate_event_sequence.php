<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate events get a running number per vehicle (seq), so two steps recorded within the same second
 * keep their order. Events are append-only, so older rows are not renumbered: they keep seq NULL and
 * sort as 0, behind everything recorded after this release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_events', function (Blueprint $table) {
            $table->unsignedBigInteger('seq')->nullable();
            $table->index(['gate_entry_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::table('gate_events', function (Blueprint $table) {
            $table->dropIndex(['gate_entry_id', 'seq']);
            $table->dropColumn('seq');
        });
    }
};

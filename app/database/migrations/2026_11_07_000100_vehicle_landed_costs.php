<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charges of one vehicle (freight, unloading, handling…) are expenses linked to its gate entry; those
 * marked "add to stock cost" are part of the lot's landed cost (StockValuation::recordLandedCost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignUuid('gate_entry_id')->nullable()->constrained('gate_entries');
            $table->boolean('capitalize_to_stock')->default(false);
            $table->index('gate_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gate_entry_id');
            $table->dropColumn('capitalize_to_stock');
        });
    }
};

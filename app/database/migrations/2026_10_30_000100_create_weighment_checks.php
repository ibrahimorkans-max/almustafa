<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weight checks: the mill's Super Admin (or the Owner) looks at each recorded weight with its evidence
 * and marks it OK or queries it. Append-only: a second look is a new row; the latest one counts and
 * all stay visible. The weighment itself never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighment_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('weighment_id')->constrained('weighments');
            $table->string('decision', 8);                         // ok | query
            $table->text('note')->nullable();                      // required for a query
            $table->uuid('checked_by');
            $table->dateTime('checked_at', 3);
            $table->index(['weighment_id', 'checked_at']);
        });

        TenantTables::secure(['weighment_checks'], ['weighment_checks']);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(['weighment_checks']);
        Schema::dropIfExists('weighment_checks');
    }
};

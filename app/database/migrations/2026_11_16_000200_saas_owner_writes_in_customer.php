<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customer's Owner working in one of its mills still writes rows of its other mills — its own sign-in
 * session and devices belong to its home mill. The customer test no longer requires "all firms" mode
 * (the application still reads one mill at a time); never another customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_in_customer(row_entity uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT app_customer_id() IS NOT NULL
                   AND EXISTS (SELECT 1 FROM entities e WHERE e.id = row_entity AND e.customer_id = app_customer_id())
            $$;
        SQL);
    }

    public function down(): void
    {
        // The earlier function is restored by rolling back 2026_11_16_000100.
    }
};

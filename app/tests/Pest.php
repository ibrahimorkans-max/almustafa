<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/** Skip a test unless running on PostgreSQL (row-level security, DB-level guarantees). */
function requiresPostgres(): void
{
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('PostgreSQL-only (row-level security).');
    }
}

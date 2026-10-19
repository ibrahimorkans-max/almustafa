<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The role shown as "Office Coordinator" gets a matching code, OFFICE_COORDINATOR (was ARCHIVE_MANAGER).
 * Its rights and its people do not change. Row-level security is FORCEd, so this runs with the system bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->recode('ARCHIVE_MANAGER', 'OFFICE_COORDINATOR');
    }

    public function down(): void
    {
        $this->recode('OFFICE_COORDINATOR', 'ARCHIVE_MANAGER');
    }

    private function recode(string $from, string $to): void
    {
        DB::transaction(function () use ($from, $to): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            DB::table('roles')->where('code', $from)->update(['code' => $to]);
        });
    }
};

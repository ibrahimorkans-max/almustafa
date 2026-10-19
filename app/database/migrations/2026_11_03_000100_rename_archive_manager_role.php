<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The archive-only role is shown under a neutral name, "Office Coordinator"; its code and rights do
 * not change. Row-level security is FORCEd, so this runs with the system bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename(['Archive manager', 'Records Officer'], 'Office Coordinator');
    }

    public function down(): void
    {
        $this->rename(['Office Coordinator', 'Records Officer'], 'Archive manager');
    }

    /** @param  list<string>  $from */
    private function rename(array $from, string $to): void
    {
        DB::transaction(function () use ($from, $to): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            DB::table('roles')->where('code', 'ARCHIVE_MANAGER')->whereIn('name', $from)->update(['name' => $to]);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The milling income ledger made by quick bills was named "Milling charges (mazdoori) received": the Roznamcha reader
 * matches lines to ledgers by name, and a plain "Mazdoori" line (labour PAID) went to it. Renamed where it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('Milling charges (mazdoori) received', "Milling income (customers' own paddy)");
    }

    public function down(): void
    {
        $this->rename("Milling income (customers' own paddy)", 'Milling charges (mazdoori) received');
    }

    private function rename(string $from, string $to): void
    {
        DB::transaction(function () use ($from, $to): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");   // every mill's ledger (row security is forced)
            }
            DB::table('ledger_accounts')->where('system_key', 'milling_income')->where('name', $from)->update(['name' => $to]);
        });
    }
};

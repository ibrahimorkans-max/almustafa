<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "disabled" becomes "suspended" (reversible); "revoked" (permanent) is new.
 * Row-level security is FORCEd (it applies to the table owner too), so the update runs with the
 * application's system bypass switch, set for this transaction only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->asSystem(fn () => DB::table('login_accounts')->where('status', 'disabled')->update(['status' => 'suspended']));
    }

    public function down(): void
    {
        $this->asSystem(fn () => DB::table('login_accounts')->whereIn('status', ['suspended', 'revoked'])->update(['status' => 'disabled']));
    }

    private function asSystem(callable $change): void
    {
        DB::transaction(function () use ($change): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            $change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Owner becomes the top account over every mill and the Super Admin each mill's administrator.
 * The global role (entity_id NULL, was SUPER_ADMIN "Super Admin") becomes OWNER "Owner"; every
 * mill's OWNER "Owner" role becomes SUPER_ADMIN "Super Admin". People keep their accounts and rights:
 * whoever held the global role is now the Owner. The global role's session limit goes from 60
 * minutes to 12 hours, like the other roles.
 *
 * Row-level security is FORCEd, so the change runs with the system bypass for this transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->asSystem(function (): void {
            DB::table('roles')->whereNotNull('entity_id')->where('code', 'OWNER')->update(['code' => '__SWAP__']);
            DB::table('roles')->whereNull('entity_id')->where('code', 'SUPER_ADMIN')
                ->update(['code' => 'OWNER', 'name' => 'Owner', 'session_max_minutes' => 720]);
            DB::table('roles')->whereNotNull('entity_id')->where('code', '__SWAP__')
                ->update(['code' => 'SUPER_ADMIN', 'name' => 'Super Admin']);
        });
    }

    public function down(): void
    {
        $this->asSystem(function (): void {
            DB::table('roles')->whereNotNull('entity_id')->where('code', 'SUPER_ADMIN')->update(['code' => '__SWAP__']);
            DB::table('roles')->whereNull('entity_id')->where('code', 'OWNER')
                ->update(['code' => 'SUPER_ADMIN', 'name' => 'Super Admin', 'session_max_minutes' => 60]);
            DB::table('roles')->whereNotNull('entity_id')->where('code', '__SWAP__')
                ->update(['code' => 'OWNER', 'name' => 'Owner']);
        });
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

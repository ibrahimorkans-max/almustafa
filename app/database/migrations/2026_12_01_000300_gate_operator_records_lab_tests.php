<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Weighbridge / Gate Operator may record a vehicle's lab test (lab.test): the "Lab test" button on
 * Vehicles at gate and Live weight, and "Record lab test" on the vehicle page. Accepting or rejecting the lot
 * stays with the Lab Supervisor (lab.decide). RoleTemplates gives it to new mills; this adds it to the mills
 * already set up. Row-level security is FORCEd, so this runs with the system bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            foreach (DB::table('roles')->where('code', 'GATE_OPERATOR')->get(['id', 'entity_id']) as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_code' => 'lab.test', 'entity_id' => $role->entity_id, 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            DB::table('role_permissions')->where('permission_code', 'lab.test')
                ->whereIn('role_id', DB::table('roles')->where('code', 'GATE_OPERATOR')->select('id'))->delete();
        });
    }
};

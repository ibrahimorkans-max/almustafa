<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The main users approve their own work (App\Domain\Approvals\SelfApproval): so that they can, the
 * Accountant may now release payments and the Purchase Manager may approve saudas, and both may decide
 * requests. Existing roles of
 * those kinds in every mill get the right; nothing is taken away. Row-level security is FORCEd, so this
 * runs with the system bypass.
 */
return new class extends Migration
{
    private const GRANTS = [
        'ACCOUNTANT' => ['payment.approve', 'approval.decide'],
        'PURCHASE_MANAGER' => ['deal.approve', 'approval.decide'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            foreach (self::GRANTS as $role => $codes) {
                foreach ($codes as $code) {
                    if (! DB::table('permissions')->where('code', $code)->exists()) {
                        continue; // fresh install: roles are made from the templates
                    }
                    $roles = DB::table('roles')->where('code', $role)
                        ->whereNotIn('id', DB::table('role_permissions')->where('permission_code', $code)->select('role_id'))
                        ->get(['id', 'entity_id']);
                    foreach ($roles as $r) {
                        DB::table('role_permissions')->insert([
                            'role_id' => $r->id, 'permission_code' => $code, 'entity_id' => $r->entity_id, 'created_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            foreach (self::GRANTS as $role => $codes) {
                DB::table('role_permissions')->whereIn('permission_code', $codes)
                    ->whereIn('role_id', DB::table('roles')->where('code', $role)->select('id'))->delete();
            }
        });
    }
};

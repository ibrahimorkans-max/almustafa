<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Office Desk (closed records past the retention period) is the Office Coordinator's alone: the Owner
 * and each mill's Super Admin no longer have it in their menu. Their grants of office.view and
 * office.restore are taken away (RoleTemplates no longer gives them). The Owner still chooses the retention
 * period, under Security → Sign-in rules. Row-level security is FORCEd, so this runs with the system bypass.
 */
return new class extends Migration
{
    private const ROLES = ['OWNER', 'SUPER_ADMIN'];

    private const CODES = ['office.view', 'office.restore'];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            DB::table('role_permissions')->whereIn('permission_code', self::CODES)
                ->whereIn('role_id', DB::table('roles')->whereIn('code', self::ROLES)->select('id'))->delete();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            foreach (DB::table('roles')->whereIn('code', self::ROLES)->get(['id']) as $role) {
                foreach (self::CODES as $code) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_code' => $code]);
                }
            }
        });
    }
};

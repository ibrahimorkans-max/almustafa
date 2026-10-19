<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Archive screen (view and restore archived records) is for the Owner and each mill's Super Admin
 * only: archive.search / archive.restore are taken from every other role, and each removal is written
 * to the audit log. Row-level security is FORCEd, so this runs with the system bypass.
 */
return new class extends Migration
{
    private const KEEP = ['OWNER', 'SUPER_ADMIN'];

    private const PERMISSIONS = ['archive.search', 'archive.restore'];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            $grants = DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->whereIn('role_permissions.permission_code', self::PERMISSIONS)->whereNotIn('roles.code', self::KEEP)
                ->get(['role_permissions.role_id', 'role_permissions.permission_code']);
            foreach ($grants->groupBy('role_id') as $roleId => $rows) {
                $codes = $rows->pluck('permission_code')->all();
                DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission_code', $codes)->delete();
                $role = Role::query()->find($roleId);
                if ($role !== null) {
                    $role->flushPermissionCache();
                    app(AuditLogger::class)->record('role.permissions.revoked', $role, ['permissions' => $codes], null, ['source' => 'release_archive_owner_only']);
                }
            }
        });
    }

    public function down(): void
    {
        // Rights are not handed back automatically; grant them again through the normal approval.
    }
};

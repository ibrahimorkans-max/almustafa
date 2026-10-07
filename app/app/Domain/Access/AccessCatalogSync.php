<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Brings the `permissions` table and the global Super Admin role in line with the code.
 * Idempotent; run on every deploy (`php artisan access:sync`).
 * Entity roles are NOT touched: their grants change only through maker-checker.
 */
final class AccessCatalogSync
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function sync(): void
    {
        $this->tenant->runAsSystem(function (): void {
            DB::transaction(function (): void {
                $now = now();
                $known = Permission::query()->pluck('code')->all();
                foreach (PermissionCatalog::rows() as $row) {
                    Permission::query()->updateOrCreate(['code' => $row['code']], $row);
                }

                $template = RoleTemplates::get(RoleTemplates::SUPER_ADMIN);
                $role = Role::query()->whereNull('entity_id')->where('code', RoleTemplates::SUPER_ADMIN)->first()
                    ?? Role::query()->create([
                        'entity_id' => null,
                        'code' => RoleTemplates::SUPER_ADMIN,
                        'name' => $template['name'],
                        'is_system' => true,
                        'is_super_admin' => true,
                        'mfa_required' => true,
                        'session_max_minutes' => $template['session_max_minutes'],
                    ]);

                $existing = $role->permissionCodes();
                $missing = array_values(array_diff($template['permissions'], $existing));
                if ($missing !== []) {
                    DB::table('role_permissions')->insert(array_map(fn (string $code) => [
                        'role_id' => $role->id, 'permission_code' => $code, 'entity_id' => null, 'created_at' => $now,
                    ], $missing));
                }

                // A fresh install has no entities yet; on upgrades, new permissions follow the templates.
                if ($known !== []) {
                    $this->grantNewPermissionsToEntityRoles(array_values(array_diff(PermissionCatalog::codes(), $known)));
                }
            });
        });
    }

    /**
     * Permissions introduced by a release are granted to existing entity roles whose template
     * includes them; without this, new features would be unusable until an approval round.
     * Removals and every other change still go through maker-checker.
     *
     * @param  list<string>  $newCodes
     */
    private function grantNewPermissionsToEntityRoles(array $newCodes): void
    {
        if ($newCodes === []) {
            return;
        }

        $templates = RoleTemplates::all();
        Role::query()->whereNotNull('entity_id')->where('is_system', true)->get()
            ->each(function (Role $role) use ($templates, $newCodes): void {
                $grant = array_values(array_intersect($newCodes, $templates[$role->code]['permissions'] ?? []));
                if ($grant === []) {
                    return;
                }

                DB::table('role_permissions')->insert(array_map(fn (string $code) => [
                    'role_id' => $role->id, 'permission_code' => $code, 'entity_id' => $role->entity_id, 'created_at' => now(),
                ], $grant));
                $role->flushPermissionCache();
                app(AuditLogger::class)->record('role.permissions.granted', $role, null, ['permissions' => $grant], ['source' => 'catalog_upgrade']);
            });
    }

    public function superAdminRole(): Role
    {
        return $this->tenant->runAsSystem(
            fn () => Role::query()->whereNull('entity_id')->where('code', RoleTemplates::SUPER_ADMIN)->firstOrFail(),
        );
    }
}

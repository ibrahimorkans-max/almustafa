<?php

declare(strict_types=1);

namespace App\Domain\Access;

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
            });
        });
    }

    public function superAdminRole(): Role
    {
        return $this->tenant->runAsSystem(
            fn () => Role::query()->whereNull('entity_id')->where('code', RoleTemplates::SUPER_ADMIN)->firstOrFail(),
        );
    }
}

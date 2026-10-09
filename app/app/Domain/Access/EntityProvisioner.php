<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Audit\AuditLogger;
use App\Domain\Hr\HrInstaller;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Creates an entity together with its default roles and grants and its chart of accounts, in one transaction.
 */
final class EntityProvisioner
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly ChartOfAccounts $chart,
        private readonly HrInstaller $hr,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Entity
    {
        return $this->tenant->runAsSystem(fn () => DB::transaction(function () use ($attributes): Entity {
            $entity = Entity::query()->create($attributes);
            $now = now();

            foreach (RoleTemplates::entityRoleCodes() as $code) {
                $template = RoleTemplates::get($code);
                $role = Role::query()->create([
                    'entity_id' => $entity->id,
                    'code' => $code,
                    'name' => $template['name'],
                    'is_system' => true,
                    'is_super_admin' => false,
                    'mfa_required' => $template['mfa_required'],
                    'session_max_minutes' => $template['session_max_minutes'],
                ]);

                DB::table('role_permissions')->insert(array_map(fn (string $permission) => [
                    'role_id' => $role->id,
                    'permission_code' => $permission,
                    'entity_id' => $entity->id,
                    'created_at' => $now,
                ], $template['permissions']));

                $this->audit->record('role.permissions.granted', $role, null, ['permissions' => $template['permissions']], ['source' => 'template']);
            }

            $this->chart->install($entity->id);
            $this->hr->install($entity->id);

            return $entity;
        }));
    }
}

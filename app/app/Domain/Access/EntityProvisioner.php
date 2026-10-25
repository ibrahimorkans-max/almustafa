<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Audit\AuditLogger;
use App\Domain\Gate\LabStandards;
use App\Domain\Hr\HrInstaller;
use App\Domain\Saas\PlanLimits;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Gate\LabParameter;
use App\Models\Masters\TaxRate;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Creates an entity together with its default roles and grants, its chart of accounts, HR defaults and
 * the two common sales-tax rates (exempt and standard), in one transaction.
 */
final class EntityProvisioner
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly ChartOfAccounts $chart,
        private readonly HrInstaller $hr,
    ) {}

    /** C0001, C0002, … */
    public static function nextCustomerCode(): string
    {
        $last = Customer::query()->where('code', 'like', 'C%')->orderByDesc('code')->value('code');

        return sprintf('C%04d', ((int) substr((string) $last, 1)) + 1);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Entity
    {
        // Every mill belongs to a customer: the one given, the signed-in Owner's, or a new one of its own.
        $attributes['customer_id'] ??= $this->tenant->customerId();

        return $this->tenant->runAsSystem(fn () => DB::transaction(function () use ($attributes): Entity {
            if ($attributes['customer_id'] !== null) {
                app(PlanLimits::class)->assertCanAddMill($attributes['customer_id']);   // the plan's mills limit
            }
            if ($attributes['customer_id'] === null) {
                $attributes['customer_id'] = Customer::query()->create([
                    'code' => self::nextCustomerCode(), 'name' => (string) ($attributes['legal_name'] ?? $attributes['name'] ?? 'New customer'),
                    'status' => Customer::ACTIVE,
                ])->id;
            }
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
            $this->tenant->runInEntity($entity->id, function (): void {
                foreach (TaxRate::DEFAULTS as [$name, $bp, $exempt]) {
                    TaxRate::query()->firstOrCreate(['name' => $name], ['sales_tax_rate_bp' => $bp, 'exempt' => $exempt]);
                }
                // The lab tests with starting limits; the mill sets its own under Lab tests & limits.
                $sort = 0;
                foreach (LabStandards::DEFAULTS as $code => [$en, $ur, $standard, $grace]) {
                    LabParameter::query()->firstOrCreate(['code' => $code, 'product_id' => null], [
                        'name_en' => $en, 'name_ur' => $ur, 'unit' => '%', 'standard_bp' => (int) round($standard * 100),
                        'grace_bp' => (int) round($grace * 100), 'sort' => $sort += 10, 'status' => 'active',
                    ]);
                }
            });

            return $entity;
        }));
    }
}

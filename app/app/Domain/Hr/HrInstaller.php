<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Tenancy\TenantContext;
use App\Models\Hr\Department;
use App\Models\Hr\SalaryComponent;

/** Default pay heads and departments for an entity. Idempotent; never changes existing rows. */
final class HrInstaller
{
    public const DEPARTMENTS = [
        ['PROD', 'Production'], ['GATE', 'Gate and weighbridge'], ['STORE', 'Stores and godowns'],
        ['LAB', 'Quality lab'], ['ACC', 'Accounts'], ['ADMIN', 'Administration'], ['MAINT', 'Maintenance'],
    ];

    public function __construct(private readonly TenantContext $tenant) {}

    public function install(string $entityId): int
    {
        return $this->tenant->runInEntity($entityId, function (): int {
            $added = 0;
            foreach (SalaryComponent::DEFAULTS as [$code, $name, $kind, $statutory, $sort]) {
                if (! SalaryComponent::query()->where('code', $code)->exists()) {
                    SalaryComponent::query()->create(['code' => $code, 'name' => $name, 'kind' => $kind, 'is_statutory' => $statutory, 'sort' => $sort]);
                    $added++;
                }
            }
            foreach (self::DEPARTMENTS as [$code, $name]) {
                if (! Department::query()->where('code', $code)->exists()) {
                    Department::query()->create(['code' => $code, 'name' => $name]);
                    $added++;
                }
            }

            return $added;
        });
    }
}

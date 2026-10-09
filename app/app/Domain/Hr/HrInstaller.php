<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Tenancy\TenantContext;
use App\Models\Hr\Department;
use App\Models\Hr\LeaveType;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\Shift;

/** Default pay heads, departments, leave types and a general shift for an entity. Idempotent; never changes existing rows. */
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
            foreach (LeaveType::DEFAULTS as [$code, $name, $paid, $annual, $carry, $maxCarry]) {
                if (! LeaveType::query()->where('code', $code)->exists()) {
                    LeaveType::query()->create(['code' => $code, 'name' => $name, 'paid' => $paid, 'annual_days' => $annual,
                        'carry_forward' => $carry, 'max_carry_days' => $maxCarry, 'half_day_allowed' => true]);
                    $added++;
                }
            }
            if (! Shift::query()->exists()) {
                Shift::query()->create(['code' => 'GEN', 'name' => 'General (09:00–18:00)', 'start_time' => '09:00', 'end_time' => '18:00',
                    'break_minutes' => 60, 'grace_late_minutes' => 10, 'grace_early_minutes' => 10, 'full_day_minutes' => 420,
                    'half_day_minutes' => 210, 'overtime_after_minutes' => 30, 'weekly_offs' => [7]]);
                $added++;
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

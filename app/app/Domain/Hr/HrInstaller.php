<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Tenancy\TenantContext;
use App\Models\Hr\Department;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveType;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\Shift;

/**
 * Default pay heads, departments, leave types, a general shift and Pakistan's fixed-date public holidays
 * (this year and next) for an entity. Idempotent; never changes existing rows. Eid ul Fitr, Eid ul Adha,
 * Ashura and Eid Milad un Nabi follow the moon and are entered by HR each year once announced.
 */
final class HrInstaller
{
    public const DEPARTMENTS = [
        ['PROD', 'Production'], ['GATE', 'Gate and weighbridge'], ['STORE', 'Stores and godowns'],
        ['LAB', 'Quality lab'], ['ACC', 'Accounts'], ['ADMIN', 'Administration'], ['MAINT', 'Maintenance'],
    ];

    /** month-day => name */
    public const NATIONAL_HOLIDAYS = [
        '02-05' => 'Kashmir Solidarity Day', '03-23' => 'Pakistan Day', '05-01' => 'Labour Day', '05-28' => 'Youm-e-Takbeer',
        '08-14' => 'Independence Day', '11-09' => 'Iqbal Day', '12-25' => 'Quaid-e-Azam Day',
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
            $year = (int) now('Asia/Karachi')->year;
            foreach ([$year, $year + 1] as $y) {
                foreach (self::NATIONAL_HOLIDAYS as $monthDay => $name) {
                    if (! Holiday::query()->whereDate('date', "{$y}-{$monthDay}")->exists()) {
                        Holiday::query()->create(['date' => "{$y}-{$monthDay}", 'name' => $name, 'kind' => 'national']);
                        $added++;
                    }
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

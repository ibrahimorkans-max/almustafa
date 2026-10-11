<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\HrSetting;
use Carbon\CarbonInterface;

/**
 * Effective-dated payroll and statutory settings (Pakistan). A value applies from its date until a
 * later row replaces it; past months keep the value they were paid with. The defaults below are
 * examples current when written — **the owner or tax adviser must confirm them** before payroll is
 * finalized (Finance Act, EOBI / PESSI notifications and the Punjab minimum-wage notification change
 * them). Money in paisa, rates in basis points (100 bp = 1 %).
 */
final class PayrollSettings
{
    /** key => [default, label] */
    public const DEFAULTS = [
        // Wages engine (phase 5)
        'ot_multiplier_bp' => [20000, 'Overtime rate (× ordinary hourly rate; Factories Act s. 47: twice)'],
        'ot_hours_per_month' => [208, 'Hours in a month for the hourly rate of monthly staff (26 days × 8 h)'],
        'ot_block_minutes' => [30, 'Overtime counted in blocks of (minutes; remainder dropped)'],
        'holiday_work_multiplier_bp' => [20000, 'Pay for a day worked on a holiday or weekly off (× day rate, in total)'],
        'daily_paid_holidays' => [false, 'Daily-wage staff are paid for public holidays'],
        // Statutory (phase 6)
        'minimum_wage_monthly_paise' => [40_000_00, 'Minimum wage per month (Punjab, unskilled adult)'],
        'eobi_wage_base_paise' => [37_000_00, 'EOBI contribution base (minimum wage notified for EOBI)'],
        'eobi_employee_bp' => [100, 'EOBI employee share (% of the base)'],
        'eobi_employer_bp' => [500, 'EOBI employer share (% of the base)'],
        'pessi_employer_bp' => [600, 'PESSI employer contribution (% of wages)'],
        'pessi_wage_ceiling_paise' => [40_000_00, 'PESSI: wages above this are not covered'],
        'fine_cap_bp' => [300, 'Fines in a month may not exceed (% of wages; Payment of Wages Act)'],
        'auto_issue_payslips' => [false, 'Issue every payslip automatically when a payroll is finalized'],
        'ack_reminder_days' => [2, 'Remind about cash salary payments still without the signature or thumb after (days)'],
        'cash_salary_limit_paise' => [25_000_00, 'Salary above this a month is paid through the bank, not cash (ITO 2001 s. 21(m))'],
        // Income tax on salary, s. 149, tax year 2025-26: [taxable income from, fixed tax, rate on the excess]
        'income_tax_slabs' => [[
            [0, 0, 0],
            [600_000_00, 0, 100],
            [1_200_000_00, 6_000_00, 1100],
            [2_200_000_00, 116_000_00, 2300],
            [3_200_000_00, 346_000_00, 3000],
            [4_100_000_00, 616_000_00, 3500],
        ], 'Income tax on salary (annual slabs: from, fixed tax, rate on the part above)'],
    ];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    public function get(string $key, CarbonInterface $on): mixed
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new BusinessRuleViolation("Unknown payroll setting {$key}.");
        }
        $row = HrSetting::query()->where('key', $key)->where('effective_from', '<=', $on->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('created_at')->orderByDesc('id')->first();

        return $row !== null ? $row->value : self::DEFAULTS[$key][0];
    }

    public function int(string $key, CarbonInterface $on): int
    {
        return (int) $this->get($key, $on);
    }

    /** Whether the value in force comes from the built-in default (not yet confirmed by the mill). */
    public function isDefault(string $key, CarbonInterface $on): bool
    {
        return ! HrSetting::query()->where('key', $key)->where('effective_from', '<=', $on->toDateString())->exists();
    }

    public function set(string $key, mixed $value, CarbonInterface $from, string $note): HrSetting
    {
        if (! ($this->actor->account()?->hasPermission('hr.payroll.approve') ?? false)) {
            throw new BusinessRuleViolation("Changing payroll settings needs 'hr.payroll.approve'.");
        }
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new BusinessRuleViolation("Unknown payroll setting {$key}.");
        }
        if (mb_strlen(trim($note)) < 3) {
            throw new BusinessRuleViolation('Give the source of the new value (e.g. the notification or Finance Act).');
        }
        $default = self::DEFAULTS[$key][0];
        $valid = match (true) {
            is_bool($default) => is_bool($value),
            is_int($default) => is_int($value) && $value >= 0,
            default => self::validSlabs($value),
        };
        if (! $valid) {
            throw new BusinessRuleViolation('The value does not have the expected form for '.self::DEFAULTS[$key][1].'.');
        }
        $old = $this->get($key, $from);
        $row = HrSetting::query()->create([
            'key' => $key, 'value' => $value, 'effective_from' => $from->toDateString(), 'note' => trim($note), 'created_at' => now(),
        ]);
        $this->audit->record('hr.payroll.setting_changed', $row, ['value' => $old], ['value' => $value], ['key' => $key, 'from' => $from->toDateString()]);

        return $row;
    }

    private static function validSlabs(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }
        $last = -1;
        foreach ($value as $slab) {
            if (! is_array($slab) || count($slab) !== 3 || ! is_int($slab[0]) || ! is_int($slab[1]) || ! is_int($slab[2]) || $slab[0] <= $last) {
                return false;
            }
            $last = $slab[0];
        }

        return $value[0][0] === 0;
    }
}

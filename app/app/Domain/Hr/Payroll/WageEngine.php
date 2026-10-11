<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Hr\Attendance\AttendanceCalculator;
use App\Domain\Hr\Payroll\Steps\AdjustmentsStep;
use App\Domain\Hr\Payroll\Steps\AdvanceRecoveryStep;
use App\Domain\Hr\Payroll\Steps\AllowanceRulesStep;
use App\Domain\Hr\Payroll\Steps\BasePayStep;
use App\Domain\Hr\Payroll\Steps\HolidayWorkStep;
use App\Domain\Hr\Payroll\Steps\IncomeTaxStep;
use App\Domain\Hr\Payroll\Steps\MinimumWageStep;
use App\Domain\Hr\Payroll\Steps\OvertimeStep;
use App\Domain\Hr\Payroll\Steps\PieceRateStep;
use App\Domain\Hr\Payroll\Steps\SocialSecurityStep;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\Employee;
use Carbon\CarbonImmutable;

/**
 * One employee's pay for one month, from the true records only: attendance (final punch status,
 * leave, holidays), the effective-dated salary structure, piece work, advances and the payroll
 * settings in force. Nothing is stored here — the payroll run snapshots the result. Every line
 * carries its basis so the payslip explains itself.
 */
final class WageEngine
{
    /**
     * Steps in order. Statutory deductions and other deductions come before advance recovery,
     * so a recovery never pushes net pay below zero.
     *
     * @var list<class-string<PayStep>>
     */
    public const STEPS = [
        BasePayStep::class,
        OvertimeStep::class,
        HolidayWorkStep::class,
        PieceRateStep::class,
        AllowanceRulesStep::class,
        AdjustmentsStep::class,
        SocialSecurityStep::class,
        IncomeTaxStep::class,
        MinimumWageStep::class,
        AdvanceRecoveryStep::class,
    ];

    public function __construct(
        private readonly AttendanceCalculator $attendance,
        private readonly PayrollSettings $settings,
    ) {}

    /**
     * @param  array{days: list<array<string, mixed>>, totals: array<string, int|float>}|null  $attendance  locked snapshot; computed when null
     * @return array<string, mixed>
     */
    public function calculate(Employee $employee, string $month, ?array $attendance = null): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new BusinessRuleViolation('Month must look like 2026-10.');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Karachi');
        $attendance ??= $this->attendance->period($employee, $start, $start->endOfMonth());
        $days = [];
        foreach ($attendance['days'] as $row) {
            $days[$row['date']] = $row;
        }
        $c = new PayContext($employee, $start, $days, $attendance['totals'], $this->settings);
        foreach (self::steps() as $step) {
            app($step)->apply($c);
        }

        $gross = $c->gross();
        $deductions = $c->totalDeductions();

        return [
            'employee_id' => $employee->id,
            'month' => $month,
            'pay_basis' => $c->info['pay_basis'] ?? $c->payBasis(),
            'info' => $c->info,
            'attendance' => $attendance['totals'],
            'earnings' => $c->earnings,
            'deductions' => $c->deductions,
            'employer' => $c->employer,
            'gross_paise' => $gross,
            'deductions_paise' => $deductions,
            'net_paise' => $gross - $deductions,
            'employer_paise' => array_sum(array_column($c->employer, 'amount_paise')),
            'warnings' => $c->warnings,
        ];
    }

    /** @return list<class-string<PayStep>> */
    public static function steps(): array
    {
        return self::STEPS;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Attendance\AttendanceCalculator;
use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;

/**
 * Overtime (Factories Act 1934 s. 47: twice the ordinary rate). Minutes come from the attendance
 * calculation (beyond the shift plus its threshold) and are counted in whole blocks. The hourly rate
 * is Basic ÷ hours per month for monthly staff, or the day rate ÷ shift hours for daily staff, in
 * force on the day the overtime was worked.
 */
final class OvertimeStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        $basis = $c->payBasis();
        if ($basis === null || $basis === 'piece_rate') {
            return;
        }
        $block = max(1, (int) $c->setting('ot_block_minutes'));
        $multiplier = (int) $c->setting('ot_multiplier_bp');
        $hoursPerMonth = max(1, (int) $c->setting('ot_hours_per_month'));
        $shiftMinutes = max(60, ($c->employee->shift ?? AttendanceCalculator::defaultShift())->scheduledMinutes());

        $sum = '0';
        $minutes = 0;
        foreach ($c->days as $date => $d) {
            $m = intdiv((int) $d['overtime_minutes'], $block) * $block;
            $structure = $c->structureOn($date);
            if ($m === 0 || $structure === null) {
                continue;
            }
            $basic = $c->amountOn($date, 'BASIC');
            $perMinuteDivisor = $structure->pay_basis === 'monthly' ? 60 * $hoursPerMonth : $shiftMinutes;
            $sum = bcadd($sum, bcdiv((string) ($basic * $m * $multiplier), (string) ($perMinuteDivisor * 10000), 6), 6);
            $minutes += $m;
        }
        $c->info['overtime_minutes'] = $minutes;
        if ($minutes === 0) {
            return;
        }
        $rate = $basis === 'monthly'
            ? sprintf('Basic ÷ %d h', $hoursPerMonth)
            : sprintf('day rate ÷ %s h', rtrim(rtrim(number_format($shiftMinutes / 60, 2), '0'), '.'));
        $c->earn('OT', 'Overtime', PayContext::round($sum), sprintf('%s h × (%s) × %s', self::hours($minutes), $rate, rtrim(rtrim(number_format($multiplier / 10000, 2), '0'), '.')));
    }

    private static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
    }
}

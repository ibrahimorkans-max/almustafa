<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;

/**
 * A day worked on a public holiday or the weekly off is paid at the holiday multiplier in total
 * (default 2 × the day rate). The ordinary day is already in base pay (holidays are paid to monthly
 * staff; days present are paid to daily staff), so this adds the difference: (multiplier − 1) × the
 * day rate × the share of the day worked. Day rate: monthly Basic ÷ calendar days, or the daily Basic.
 */
final class HolidayWorkStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        $basis = $c->payBasis();
        if ($basis === null || $basis === 'piece_rate') {
            return;
        }
        $extraBp = (int) $c->setting('holiday_work_multiplier_bp') - 10000;
        if ($extraBp <= 0) {
            return;
        }
        $sum = '0';
        $daysWorked = 0.0;
        foreach ($c->days as $date => $d) {
            $onHoliday = in_array('worked_on_holiday', $d['notes'], true) || in_array('worked_on_weekly_off', $d['notes'], true);
            $structure = $c->structureOn($date);
            if (! $onHoliday || (float) $d['present'] <= 0 || $structure === null) {
                continue;
            }
            $basic = $c->amountOn($date, 'BASIC');
            $divisor = $structure->pay_basis === 'monthly' ? $c->daysInMonth : 1;
            $halves = (int) round((float) $d['present'] * 2);
            $sum = bcadd($sum, bcdiv((string) ($basic * $halves * $extraBp), (string) ($divisor * 2 * 10000), 6), 6);
            $daysWorked += $halves / 2;
        }
        if ($daysWorked > 0) {
            $c->earn('HOLIDAY_WORK', 'Work on holidays / weekly off', PayContext::round($sum),
                sprintf('%s day(s) × day rate × %s extra', rtrim(rtrim(number_format($daysWorked, 1), '0'), '.'), rtrim(rtrim(number_format($extraBp / 10000, 2), '0'), '.')));
        }
        $c->info['holiday_days_worked'] = $daysWorked;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;

/**
 * Warns when a wage rate is below the minimum wage in force (Punjab notification): a monthly rate
 * below it, or a day rate × 26 below it. The payroll still pays what the structure says — the
 * structure must be corrected — but the warning shows on the review screen and in the alerts.
 */
final class MinimumWageStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        $structure = $c->structureOn($c->monthEnd()->toDateString());
        if ($structure === null) {
            return;
        }
        $minimum = (int) $c->setting('minimum_wage_monthly_paise');
        $rate = match ($structure->pay_basis) {
            'monthly' => $structure->totalPaise('earning'),
            'daily' => $structure->totalPaise('earning') * 26,
            default => null,
        };
        if ($rate !== null && $rate < $minimum) {
            $c->warnings[] = sprintf('Wage rate %s a month is below the minimum wage %s.', PayContext::money($rate), PayContext::money($minimum));
            $c->info['below_minimum_wage'] = 1;
        }
    }
}

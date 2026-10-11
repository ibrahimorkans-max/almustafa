<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;

/**
 * EOBI (Employees' Old-Age Benefits Act 1976): employee and employer shares are percentages of the
 * notified minimum-wage base, due for any month the employee is on the rolls and paid. PESSI
 * (Punjab Employees Social Security): the employer contributes a percentage of the wages of
 * employees whose wages are within the coverage ceiling. Employees can be marked as not covered.
 */
final class SocialSecurityStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        if ($c->gross() <= 0 || $c->employedDays() === 0) {
            return;
        }
        if ($c->employee->eobi_applicable) {
            $base = (int) $c->setting('eobi_wage_base_paise');
            $ee = (int) $c->setting('eobi_employee_bp');
            $er = (int) $c->setting('eobi_employer_bp');
            $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%';
            $c->deduct('EOBI', 'EOBI (employee share)', PayContext::round(bcdiv((string) ($base * $ee), '10000', 6)), sprintf('%s of %s', $pct($ee), PayContext::money($base)));
            $c->contribute('EOBI_ER', 'EOBI (employer share)', PayContext::round(bcdiv((string) ($base * $er), '10000', 6)), sprintf('%s of %s', $pct($er), PayContext::money($base)));
        }
        if ($c->employee->pessi_applicable) {
            $ceiling = (int) $c->setting('pessi_wage_ceiling_paise');
            $rate = $this->monthlyRate($c);
            if ($rate <= $ceiling) {
                $wages = min($c->gross(), $ceiling);
                $bp = (int) $c->setting('pessi_employer_bp');
                $c->contribute('PESSI_ER', 'PESSI (employer)', PayContext::round(bcdiv((string) ($wages * $bp), '10000', 6)),
                    sprintf('%s of wages %s', rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%', PayContext::money($wages)));
            } else {
                $c->info['pessi'] = 'not covered (wages above the ceiling)';
            }
        }
    }

    /** Full-month wage rate: the structure's monthly earnings, or the month's gross for daily / piece-rate staff. */
    private function monthlyRate(PayContext $c): int
    {
        $structure = $c->structureOn($c->monthEnd()->toDateString());
        if ($structure !== null && $structure->pay_basis === 'monthly') {
            return $structure->totalPaise('earning');
        }

        return $c->gross();
    }
}

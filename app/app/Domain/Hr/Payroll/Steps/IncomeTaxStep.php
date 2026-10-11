<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayrollYtd;
use App\Domain\Hr\Payroll\PayStep;

/**
 * Income tax on salary withheld by the employer (Income Tax Ordinance 2001, s. 149). The year's
 * salary is estimated as what was paid so far this tax year (finalized payroll) + this month + the
 * regular monthly pay for the months left; the tax on it by the slabs in force, less the tax already
 * withheld, is spread evenly over this and the remaining months. All earnings are treated as
 * taxable salary (exemptions are for the tax adviser to set up as separate pay heads).
 */
final class IncomeTaxStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        $gross = $c->gross();
        if ($gross <= 0) {
            return;
        }
        $month = $c->month();
        $ytd = app(PayrollYtd::class)->before($c->employee->id, $month);
        $n = PayrollYtd::monthOfTaxYear($month);
        $left = 12 - $n;
        $regular = $this->regularMonthly($c, $gross);
        $annual = $ytd['gross_paise'] + $gross + $left * $regular;
        $annualTax = self::taxOn($annual, (array) $c->setting('income_tax_slabs'));
        $due = max(0, $annualTax - $ytd['tax_paise']);
        $thisMonth = intdiv($due + intdiv($left + 1, 2), $left + 1); // even split, rounded half up

        $c->info['tax_projected_annual_paise'] = $annual;
        $c->info['tax_annual_paise'] = $annualTax;
        $c->info['tax_ytd_paise'] = $ytd['tax_paise'];
        $c->deduct('TAX', 'Income tax (s. 149)', $thisMonth, sprintf(
            'estimated yearly salary %s, tax %s, already withheld %s, over %d month(s)',
            PayContext::money($annual), PayContext::money($annualTax), PayContext::money($ytd['tax_paise']), $left + 1,
        ));
    }

    /**
     * Tax by the slabs: [from, fixed tax, rate on the part above `from`], the highest slab not above the income.
     *
     * @param  array<int, array{0: int, 1: int, 2: int}>  $slabs
     */
    public static function taxOn(int $annualPaise, array $slabs): int
    {
        $tax = 0;
        foreach ($slabs as [$from, $fixed, $bp]) {
            if ($annualPaise >= $from) {
                $tax = $fixed + intdiv(($annualPaise - $from) * $bp * 2 + 10000, 20000);
            }
        }

        return $tax;
    }

    /** Regular monthly pay for the months ahead: the structure's monthly earnings, or this month's pay. */
    private function regularMonthly(PayContext $c, int $gross): int
    {
        $structure = $c->structureOn($c->monthEnd()->toDateString());

        return $structure !== null && $structure->pay_basis === 'monthly' ? $structure->totalPaise('earning') : $gross;
    }
}

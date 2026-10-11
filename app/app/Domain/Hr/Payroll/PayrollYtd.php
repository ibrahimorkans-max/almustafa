<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use Carbon\CarbonImmutable;

/**
 * Year-to-date pay of an employee from finalized payroll only (the current calculation of each
 * finalized run), within the Pakistani tax year (July–June) and before a given month.
 */
final class PayrollYtd
{
    /** First month (Y-m) of the tax year that contains $month. */
    public static function taxYearStart(string $month): string
    {
        $d = CarbonImmutable::createFromFormat('!Y-m', $month);

        return ($d->month >= 7 ? $d->year : $d->year - 1).'-07';
    }

    /** Months of the tax year up to and including $month (July = 1 … June = 12). */
    public static function monthOfTaxYear(string $month): int
    {
        $m = (int) substr($month, 5, 2);

        return $m >= 7 ? $m - 6 : $m + 6;
    }

    /** @return array{gross_paise: int, tax_paise: int, net_paise: int, months: int} */
    public function before(string $employeeId, string $month): array
    {
        $from = self::taxYearStart($month);
        $runs = PayrollRun::query()->where('status', PayrollRun::FINALIZED)->where('month', '>=', $from)->where('month', '<', $month)->get(['id', 'calculation_no']);
        $out = ['gross_paise' => 0, 'tax_paise' => 0, 'net_paise' => 0, 'months' => 0];
        foreach ($runs as $run) {
            $item = PayrollItem::query()->where('payroll_run_id', $run->id)->where('calculation_no', $run->calculation_no)->where('employee_id', $employeeId)->first();
            if ($item === null) {
                continue;
            }
            $out['gross_paise'] += $item->gross_paise;
            $out['tax_paise'] += $item->line('deductions', 'TAX');
            $out['net_paise'] += $item->net_paise;
            $out['months']++;
        }

        return $out;
    }
}

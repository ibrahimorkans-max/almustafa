<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Support\Values\PakistanIds;

/**
 * Statutory returns and registers of a month, from the finalized payroll only (what was actually
 * paid): EOBI contributions, PESSI contributions, the s. 149 salary-tax statement, the wages register
 * and the overtime register. Full CNICs appear only for users with hr.employee.sensitive.view; others
 * get the masked number. Columns follow the forms' usual order; the institutions change their
 * upload formats now and then, so the accountant checks them before filing.
 */
final class StatutoryReports
{
    public const REPORTS = [
        'eobi' => 'EOBI contributions (PR-01)',
        'pessi' => 'PESSI contributions',
        'tax149' => 'Income tax on salary (s. 149) statement',
        'wages' => 'Wages register',
        'overtime' => 'Overtime register',
    ];

    public function __construct(private readonly ActorContext $actor) {}

    /** @return array{title: string, header: list<string>, rows: list<list<string|int|float|null>>} */
    public function build(string $report, string $month): array
    {
        if (! array_key_exists($report, self::REPORTS)) {
            throw new BusinessRuleViolation('Unknown report.');
        }
        $run = PayrollRun::query()->where('month', $month)->where('status', PayrollRun::FINALIZED)->first()
            ?? throw new BusinessRuleViolation("The {$month} payroll is not finalized yet; statutory returns are made from finalized payroll only.");
        $items = $run->currentItems()->orderBy('employee->code')->get();
        $full = $this->actor->account()?->hasPermission('hr.employee.sensitive.view') ?? false;
        $cnics = $full ? Employee::query()->whereKey($items->pluck('employee_id'))->get()->mapWithKeys(fn (Employee $e) => [$e->id => PakistanIds::formatCnic($e->cnic)])->all() : [];
        $cnic = fn (PayrollItem $i) => $full ? ($cnics[$i->employee_id] ?? null) : ($i->employee['cnic_last4'] ? 'XXXXX-XXXX'.substr((string) $i->employee['cnic_last4'], 0, 3).'-'.substr((string) $i->employee['cnic_last4'], 3) : null);
        $rs = fn (int $p) => round($p / 100, 2);
        $title = self::REPORTS[$report]." — {$month}";

        return match ($report) {
            'eobi' => ['title' => $title, 'header' => ['Sr', 'EOBI number', 'Name', 'CNIC', 'Employee share', 'Employer share', 'Total'],
                'rows' => $this->rows($items, fn (PayrollItem $i) => $i->line('deductions', 'EOBI') > 0 || $i->line('employer', 'EOBI_ER') > 0,
                    fn (PayrollItem $i) => [$i->employee['eobi_number'], $i->employee['name'], $cnic($i), $rs($i->line('deductions', 'EOBI')),
                        $rs($i->line('employer', 'EOBI_ER')), $rs($i->line('deductions', 'EOBI') + $i->line('employer', 'EOBI_ER'))])],
            'pessi' => ['title' => $title, 'header' => ['Sr', 'PESSI number', 'Name', 'CNIC', 'Wages', 'Contribution'],
                'rows' => $this->rows($items, fn (PayrollItem $i) => $i->line('employer', 'PESSI_ER') > 0,
                    fn (PayrollItem $i) => [$i->employee['pessi_number'], $i->employee['name'], $cnic($i), $rs($i->gross_paise), $rs($i->line('employer', 'PESSI_ER'))])],
            'tax149' => ['title' => $title, 'header' => ['Sr', 'Name', 'CNIC', 'Gross salary', 'Tax deducted', 'Estimated yearly salary', 'Yearly tax'],
                'rows' => $this->rows($items, fn (PayrollItem $i) => $i->gross_paise > 0,
                    fn (PayrollItem $i) => [$i->employee['name'], $cnic($i), $rs($i->gross_paise), $rs($i->line('deductions', 'TAX')),
                        $rs((int) ($i->result['info']['tax_projected_annual_paise'] ?? 0)), $rs((int) ($i->result['info']['tax_annual_paise'] ?? 0))])],
            'wages' => ['title' => $title, 'header' => ['Sr', 'Code', 'Name', 'Designation', 'Pay basis', 'Paid days', 'Overtime hours', 'Gross wages', 'Deductions', 'Net paid', 'Payslip'],
                'rows' => $this->rows($items, fn (PayrollItem $i) => $i->gross_paise > 0,
                    fn (PayrollItem $i) => [$i->employee['code'], $i->employee['name'], $i->employee['designation'], $i->result['pay_basis'],
                        $i->result['info']['paid_days'] ?? null, round(((int) ($i->result['info']['overtime_minutes'] ?? 0)) / 60, 2),
                        $rs($i->gross_paise), $rs($i->deductions_paise), $rs($i->net_paise), $i->payslip?->number])],
            'overtime' => ['title' => $title, 'header' => ['Sr', 'Code', 'Name', 'Overtime hours', 'Overtime pay', 'Holiday work pay'],
                'rows' => $this->rows($items, fn (PayrollItem $i) => $i->line('earnings', 'OT') > 0 || $i->line('earnings', 'HOLIDAY_WORK') > 0,
                    fn (PayrollItem $i) => [$i->employee['code'], $i->employee['name'], round(((int) ($i->result['info']['overtime_minutes'] ?? 0)) / 60, 2),
                        $rs($i->line('earnings', 'OT')), $rs($i->line('earnings', 'HOLIDAY_WORK'))])],
        };
    }

    /**
     * @param  iterable<PayrollItem>  $items
     * @return list<list<string|int|float|null>>
     */
    private function rows(iterable $items, callable $include, callable $map): array
    {
        $rows = [];
        foreach ($items as $item) {
            if ($include($item)) {
                $rows[] = [count($rows) + 1, ...$map($item)];
            }
        }

        return $rows;
    }
}

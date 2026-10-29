<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Accounts\FinancialReports;
use App\Domain\Saas\DataVisibility;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;

/**
 * Turns the financial reports into one table shape that the screen, the PDF and the Excel export all
 * render, so the three always agree:
 * ['title', 'subtitle', 'columns' => [label, …], 'rows' => [['label', 'cells' => [paise|string|null …], 'style', 'ledger'?]], 'notes' => [...]].
 * Integer cells are paise; style is line | heading | total | grand.
 */
final class FinancialTables
{
    public const REPORTS = [
        'pl' => 'Profit and loss', 'monthly' => 'Month by month', 'budget' => 'Income and expenses against budget',
        'payroll' => 'Payroll cost by department', 'cashflow' => 'Cash flow', 'staff' => 'Salaries, claims and advances outstanding',
    ];

    public function __construct(private readonly FinancialReports $reports) {}

    /**
     * @param  array{from?: string, to?: string, month?: string}  $p
     * @return array{title: string, subtitle: string, columns: list<string>, rows: list<array{label: string, cells: list<int|string|null>, style: string, ledger?: string}>, notes: list<string>}
     */
    public function build(string $report, array $p): array
    {
        $from = DataVisibility::clampDate(CarbonImmutable::parse($p['from'] ?? now('Asia/Karachi')->startOfMonth()->toDateString(), 'Asia/Karachi'), 'accounts');
        $to = CarbonImmutable::parse($p['to'] ?? now('Asia/Karachi')->toDateString(), 'Asia/Karachi');
        $period = $from->format('d M Y').' to '.$to->format('d M Y');

        return match ($report) {
            'monthly' => $this->monthly($from, $to, $period),
            'budget' => $this->budget((string) ($p['month'] ?? $to->format('Y-m'))),
            'payroll' => $this->payroll($from->format('Y-m'), $to->format('Y-m')),
            'cashflow' => $this->cashFlow($from, $to, $period),
            'staff' => $this->staff($to),
            default => $this->profitAndLoss($from, $to, $period),
        };
    }

    /** Money with thousands separators; negatives in brackets, as accountants write them. */
    public static function money(int $paise): string
    {
        $s = Money::paise(abs($paise))->grouped();

        return $paise < 0 ? "({$s})" : $s;
    }

    /** @return array{title: string, subtitle: string, columns: list<string>, rows: list<array<string, mixed>>, notes: list<string>} */
    private function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to, string $period): array
    {
        $pl = $this->reports->profitAndLoss($from, $to);
        $rows = [];
        $section = function (string $heading, array $group, string $totalLabel) use (&$rows): void {
            $rows[] = ['label' => $heading, 'cells' => [null], 'style' => 'heading'];
            foreach ($group['lines'] as $l) {
                $rows[] = ['label' => $l['name'], 'cells' => [$l['amount']], 'style' => 'line', 'ledger' => $l['ledger_id']];
            }
            $rows[] = ['label' => $totalLabel, 'cells' => [$group['total']], 'style' => 'total'];
        };
        $section('Sales', $pl['sales'], 'Net sales');
        if ($pl['direct_incomes']['lines'] !== []) {
            $section('Direct income', $pl['direct_incomes'], 'Total direct income');
        }
        $rows[] = ['label' => 'Cost of goods sold', 'cells' => [null], 'style' => 'heading'];
        $rows[] = ['label' => 'Opening stock ('.$from->subDay()->format('d M Y').')', 'cells' => [$pl['opening_stock']], 'style' => 'line'];
        foreach ([...$pl['purchases']['lines'], ...$pl['direct_expenses']['lines']] as $l) {
            $rows[] = ['label' => $l['name'], 'cells' => [$l['amount']], 'style' => 'line', 'ledger' => $l['ledger_id']];
        }
        $rows[] = ['label' => 'Less closing stock ('.$to->format('d M Y').')', 'cells' => [-$pl['closing_stock']], 'style' => 'line'];
        $rows[] = ['label' => 'Cost of goods sold', 'cells' => [$pl['cost_of_goods']], 'style' => 'total'];
        $rows[] = ['label' => $pl['gross_profit'] >= 0 ? 'Gross profit' : 'Gross loss', 'cells' => [$pl['gross_profit']], 'style' => 'grand'];
        $section('Operating expenses', $pl['indirect_expenses'], 'Total operating expenses');
        if ($pl['indirect_incomes']['lines'] !== []) {
            $section('Other income', $pl['indirect_incomes'], 'Total other income');
        }
        $rows[] = ['label' => $pl['net_profit'] >= 0 ? 'Net profit' : 'Net loss', 'cells' => [$pl['net_profit']], 'style' => 'grand'];
        $cpm = $this->reports->costPerMaund($from, $to);
        $notes = ['Payroll cost included in operating expenses: Rs '.self::money($pl['payroll_cost']).'.'];
        $notes[] = $cpm['per_maund'] === null
            ? 'Milling cost per maund: no paddy was issued to milling in this period.'
            : 'Milling cost per maund: Rs '.self::money($cpm['per_maund']).' (direct and operating expenses, purchases left out, over '.$cpm['maunds'].' maunds of paddy milled).';
        $notes[] = 'Stock is valued at weighted-average cost on each date. Purchases are expensed when billed.';

        return ['title' => 'Profit and loss', 'subtitle' => $period, 'columns' => ['', 'Rs'], 'rows' => $rows, 'notes' => $notes];
    }

    /** @return array{title: string, subtitle: string, columns: list<string>, rows: list<array<string, mixed>>, notes: list<string>} */
    private function monthly(CarbonImmutable $from, CarbonImmutable $to, string $period): array
    {
        $data = $this->reports->monthly($from, $to);
        $keys = ['sales', 'cost_of_goods', 'gross_profit', 'operating_expenses', 'other_income', 'net_profit'];
        $rows = array_map(fn (array $m) => ['label' => CarbonImmutable::parse($m['month'].'-01')->format('M Y'),
            'cells' => array_map(fn (string $k) => $m[$k], $keys), 'style' => 'line'], $data);
        $rows[] = ['label' => 'Total', 'cells' => array_map(fn (string $k) => array_sum(array_column($data, $k)), $keys), 'style' => 'total'];

        return ['title' => 'Profit and loss month by month', 'subtitle' => $period,
            'columns' => ['Month', 'Sales', 'Cost of goods', 'Gross profit', 'Operating expenses', 'Other income', 'Net profit'], 'rows' => $rows,
            'notes' => ['Months are calendar months within the period; the first and last may be partial.']];
    }

    /** @return array{title: string, subtitle: string, columns: list<string>, rows: list<array<string, mixed>>, notes: list<string>} */
    private function budget(string $month): array
    {
        $rows = [];
        $shared = false;
        foreach (['expense' => 'Expenses', 'income' => 'Income'] as $kind => $heading) {
            $group = array_values(array_filter($this->reports->budgetVsActual($month), fn (array $r) => $r['kind'] === $kind));
            if ($group === []) {
                continue;
            }
            $rows[] = ['label' => $heading, 'cells' => [null, null, null], 'style' => 'heading'];
            foreach ($group as $r) {
                $shared = $shared || $r['shared_ledger'];
                $rows[] = ['label' => $r['name'].($r['shared_ledger'] ? ' *' : ''), 'cells' => [$r['budget'], $r['actual'], $r['variance']], 'style' => 'line', 'ledger' => $r['ledger_id']];
            }
            $rows[] = ['label' => "Total {$heading}", 'style' => 'total', 'cells' => [
                array_sum(array_map(fn ($r) => (int) $r['budget'], $group)), array_sum(array_column($group, 'actual')),
                array_sum(array_map(fn ($r) => (int) $r['variance'], array_filter($group, fn ($r) => $r['budget'] !== null))),
            ]];
        }
        $notes = ['Variance = actual − budget; for expenses a positive variance is over budget. Categories without a budget show no variance.'];
        if ($shared) {
            $notes[] = '* This category shares its ledger with another category; the actual is the whole ledger.';
        }

        return ['title' => 'Income and expenses against budget', 'subtitle' => CarbonImmutable::parse($month.'-01')->format('F Y'),
            'columns' => ['Category', 'Budget', 'Actual', 'Variance'], 'rows' => $rows, 'notes' => $notes];
    }

    /** @return array{title: string, subtitle: string, columns: list<string>, rows: list<array<string, mixed>>, notes: list<string>} */
    private function payroll(string $fromMonth, string $toMonth): array
    {
        $data = $this->reports->payrollCost($fromMonth, $toMonth);
        $rows = array_map(fn (array $r) => ['label' => $r['department'], 'cells' => [(string) $r['headcount'], $r['gross'], $r['employer'], $r['cost']], 'style' => 'line'], $data['rows']);
        $t = $data['total'];
        $rows[] = ['label' => 'Total', 'cells' => [(string) $t['headcount'], $t['gross'], $t['employer'], $t['cost']], 'style' => 'total'];

        return ['title' => 'Payroll cost by department', 'subtitle' => $fromMonth === $toMonth ? $fromMonth : "{$fromMonth} to {$toMonth}",
            'columns' => ['Department', 'Employees', 'Gross pay', 'Employer EOBI / PESSI', 'Total cost'], 'rows' => $rows,
            'notes' => [$data['months'] === [] ? 'No payroll was finalized in these months.' : 'Finalized payrolls: '.implode(', ', $data['months']).'. Employees are counted once per department.']];
    }

    /** @return array{title: string, subtitle: string, columns: list<string>, rows: list<array<string, mixed>>, notes: list<string>} */
    private function cashFlow(CarbonImmutable $from, CarbonImmutable $to, string $period): array
    {
        $cf = $this->reports->cashFlow($from, $to);
        $rows = [['label' => 'Cash and bank at the start', 'cells' => [$cf['opening']], 'style' => 'total']];
        foreach (['Money in' => $cf['inflows'], 'Money out' => $cf['outflows']] as $heading => $flows) {
            $rows[] = ['label' => $heading, 'cells' => [null], 'style' => 'heading'];
            foreach ($flows as $label => $amount) {
                $rows[] = ['label' => $label, 'cells' => [$heading === 'Money in' ? $amount : -$amount], 'style' => 'line'];
            }
            $rows[] = ['label' => "Total {$heading}", 'cells' => [$heading === 'Money in' ? array_sum($flows) : -array_sum($flows)], 'style' => 'total'];
        }
        $rows[] = ['label' => 'Cash and bank at the end', 'cells' => [$cf['closing']], 'style' => 'grand'];

        return ['title' => 'Cash flow', 'subtitle' => $period, 'columns' => ['', 'Rs'], 'rows' => $rows,
            'notes' => ['All cash and bank ledgers together; transfers between them are left out.']];
    }

    /** @return array{title: string, subtitle: string, columns: list<string>, rows: list<array<string, mixed>>, notes: list<string>} */
    private function staff(CarbonImmutable $asOf): array
    {
        $s = $this->reports->staffBalances($asOf);
        $rows = [
            ['label' => 'Owed to staff', 'cells' => [null], 'style' => 'heading'],
            ['label' => 'Salaries and wages payable', 'cells' => [$s['salaries_payable']], 'style' => 'line'],
            ['label' => 'Staff expense claims payable', 'cells' => [$s['claims_payable']], 'style' => 'line'],
            ['label' => 'Total owed to staff', 'cells' => [$s['salaries_payable'] + $s['claims_payable']], 'style' => 'total'],
            ['label' => 'Advances and loans still to recover', 'cells' => [null], 'style' => 'heading'],
        ];
        foreach ($s['advances'] as $a) {
            $rows[] = ['label' => "{$a['employee']} ({$a['number']})", 'cells' => [$a['balance']], 'style' => 'line'];
        }
        $rows[] = ['label' => 'Total advances outstanding', 'cells' => [$s['advances_total']], 'style' => 'total'];

        return ['title' => 'Salaries, claims and advances outstanding', 'subtitle' => 'As on '.$asOf->format('d M Y'), 'columns' => ['', 'Rs'], 'rows' => $rows,
            'notes' => ['Payables are the ledger balances on the date; advances are the balances still to be recovered today.']];
    }
}

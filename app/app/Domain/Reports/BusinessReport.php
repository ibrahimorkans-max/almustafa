<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Accounts\AccountReports;
use App\Domain\Accounts\FinancialReports;
use App\Domain\Saas\DataVisibility;
use App\Models\Accounts\Expense;
use App\Models\Accounts\Payment;
use App\Models\Claims\Claim;
use App\Models\DailyWages\DailyWageSheet;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Weighment;
use App\Models\Gate\WeighmentCheck;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;

/**
 * The whole business for a period in one go (the audit log is left out on purpose): the key figures,
 * profit and loss, income, expenses, purchases, payments, cash flow, money owed both ways, stock,
 * vehicles in and out, weight checks, production, payroll and claims. Every figure comes from the
 * same services as the individual reports, for the working mill. Money is in paise; the PDF and the
 * Excel workbook format it as Rs.
 */
final class BusinessReport
{
    public function __construct(
        private readonly FinancialReports $financial,
        private readonly AccountReports $accounts,
        private readonly OperationalReports $operational,
    ) {}

    /**
     * @return array{from: string, to: string, generated_at: string, headline: list<array{0: string, 1: string}>,
     *     sections: list<array{title: string, columns: list<string>, rows: list<list<string|int|float|null>>, money: list<int>}>}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = DataVisibility::clampDate($from);
        $pl = $this->financial->profitAndLoss($from, $to);
        $cash = $this->financial->cashFlow($from, $to);
        $stock = $this->financial->stockValueAt($to);
        $receivable = $this->accounts->receivablesAgeing($to);
        $payable = $this->accounts->payablesAgeing($to);
        [$start, $end] = [CarbonImmutable::parse($from->toDateString(), 'Asia/Karachi')->startOfDay()->utc(), CarbonImmutable::parse($to->toDateString(), 'Asia/Karachi')->endOfDay()->utc()];

        $gate = GateEntry::query()->with('party')->whereNull('archived_at')->whereBetween('arrived_at', [$start, $end])->orderBy('arrived_at')->get();
        $inbound = $gate->where('direction', 'inbound');
        $outbound = $gate->where('direction', 'outbound');
        $expenses = Expense::query()->with('category')->where('status', Expense::POSTED)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])->orderBy('entry_date')->get();
        $payments = Payment::query()->with('party')->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])->orderBy('payment_date')->get();
        $claims = Claim::query()->with('party')->whereNull('archived_at')->whereBetween('created_at', [$start, $end])->orderBy('created_at')->get();
        $weights = Weighment::query()->whereBetween('captured_at', [$start, $end])->pluck('id');
        $checked = WeighmentCheck::query()->whereIn('weighment_id', $weights)->get()->groupBy('weighment_id')
            ->map(fn ($c) => $c->sortBy(fn (WeighmentCheck $x) => $x->checked_at->format('Uu'))->last()->decision);

        $wages = DailyWageSheet::query()->with(['group', 'category'])->whereIn('status', [DailyWageSheet::POSTED, DailyWageSheet::PAID])
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->orderBy('work_date')->orderBy('number')->get();
        $wagesOwed = (int) DailyWageSheet::query()->where('status', DailyWageSheet::POSTED)->sum('net_paise');
        $income = $pl['sales']['total'] + $pl['direct_incomes']['total'] + $pl['indirect_incomes']['total'];
        $spent = $pl['purchases']['total'] + $pl['direct_expenses']['total'] + $pl['indirect_expenses']['total'];
        $net = $pl['net_profit'];
        $rs = fn (int $p) => Money::paise($p)->format();
        $kg = fn ($rows) => number_format((float) $rows->sum(fn (GateEntry $e) => (float) ($e->net_kg ?? 0)), 0);

        $headline = [
            ['Sales and other income', $rs($income)],
            ['Purchases and expenses', $rs($spent)],
            ['Gross profit', $rs($pl['gross_profit'])],
            [$net >= 0 ? 'Net PROFIT' : 'Net LOSS', $rs(abs($net))],
            ['Payroll cost (in expenses)', $rs($pl['payroll_cost'])],
            ['Daily wages (casual labour, in expenses)', $rs((int) $wages->sum('net_paise')).' · '.$wages->sum('workers').' worker-days'.($wagesOwed > 0 ? ' · still owed '.$rs($wagesOwed) : '')],
            ['Cash and bank: opening → closing', $rs($cash['opening']).' → '.$rs($cash['closing'])],
            ['Customers owe us', $rs(array_sum(array_column($receivable, 'outstanding')))],
            ['We owe suppliers', $rs(array_sum(array_column($payable, 'outstanding')))],
            ['Stock value at '.$to->format('d M Y'), $rs($stock['total'])],
            ['Vehicles in (inbound)', $inbound->count().' · net '.$kg($inbound).' kg'],
            ['Vehicles out (outbound)', $outbound->count().' · net '.$kg($outbound).' kg'],
            ['Weights checked OK / queried / not checked', $checked->filter(fn ($d) => $d === 'ok')->count().' / '.$checked->filter(fn ($d) => $d === 'query')->count().' / '.($weights->count() - $checked->count())],
        ];

        $plRows = [];
        foreach ([['Sales', $pl['sales']], ['Direct income', $pl['direct_incomes']], ['Purchases', $pl['purchases']], ['Direct expenses', $pl['direct_expenses']],
            ['Operating expenses', $pl['indirect_expenses']], ['Other income', $pl['indirect_incomes']]] as [$head, $block]) {
            foreach ($block['lines'] as $line) {
                $plRows[] = [$head, (string) ($line['name'] ?? $line['ledger'] ?? ''), (int) ($line['amount'] ?? 0)];
            }
            $plRows[] = [$head, 'Total', (int) $block['total']];
        }
        array_push($plRows,
            ['Stock', 'Opening stock', $pl['opening_stock']], ['Stock', 'Closing stock', $pl['closing_stock']],
            ['Result', 'Cost of goods sold', $pl['cost_of_goods']], ['Result', 'Gross profit', $pl['gross_profit']],
            ['Result', $net >= 0 ? 'Net profit' : 'Net loss', $net],
        );

        $gateRow = fn (GateEntry $e) => [
            $e->arrived_at->timezone('Asia/Karachi')->format('d M Y H:i'), $e->number, $e->registration_no, $e->party?->name,
            $e->gross_kg, $e->tare_kg, $e->net_kg, $e->payable_kg, str_replace('_', ' ', $e->status),
        ];
        $gateCols = ['Arrived', 'Entry', 'Vehicle', 'Party', 'Gross kg', 'Tare kg', 'Net kg', 'Payable kg', 'Status'];
        [$salesCols, $salesRows] = $this->operational->run('sales_register', $from, $to);
        [$buyCols, $buyRows] = $this->operational->run('purchase_register', $from, $to);
        [$yieldCols, $yieldRows] = $this->operational->run('production_yield', $from, $to);
        $payroll = $this->financial->payrollCost($from->format('Y-m'), $to->format('Y-m'));

        $sections = [
            ['title' => 'Profit and loss', 'columns' => ['Part', 'Account', 'Amount'], 'rows' => $plRows, 'money' => [2]],
            ['title' => 'Income: sales invoices', 'columns' => $salesCols, 'rows' => $salesRows, 'money' => []],
            ['title' => 'Expenses and other income entered', 'columns' => ['Date', 'Number', 'Kind', 'Category', 'Description', 'Amount'],
                'rows' => $expenses->map(fn (Expense $x) => [$x->entry_date->format('d M Y'), $x->number, $x->kind, $x->category?->name, $x->description, $x->amount_paise])->values()->all(), 'money' => [5]],
            ['title' => 'Purchases: paddy bills', 'columns' => $buyCols, 'rows' => $buyRows, 'money' => []],
            ['title' => 'Payments', 'columns' => ['Date', 'Number', 'Paid to', 'Mode', 'Status', 'Amount'],
                'rows' => $payments->map(fn (Payment $p) => [$p->payment_date->format('d M Y'), $p->number, $p->party?->name, $p->mode, $p->status, $p->amount_paise])->values()->all(), 'money' => [5]],
            ['title' => 'Cash flow', 'columns' => ['Direction', 'Item', 'Amount'], 'rows' => array_merge(
                [['Opening', 'Cash and bank', $cash['opening']]],
                array_map(fn ($k, $v) => ['In', (string) $k, (int) $v], array_keys($cash['inflows']), $cash['inflows']),
                array_map(fn ($k, $v) => ['Out', (string) $k, (int) $v], array_keys($cash['outflows']), $cash['outflows']),
                [['Closing', 'Cash and bank', $cash['closing']]],
            ), 'money' => [2]],
            ['title' => 'Customers who owe us', 'columns' => ['Customer', 'Invoice', 'Due', 'Status', 'Outstanding'],
                'rows' => array_map(fn ($r) => [$r['party'] ?? '', $r['number'] ?? '', $r['due_date'] ?? '', $r['bucket'] ?? '', (int) $r['outstanding']], $receivable), 'money' => [4]],
            ['title' => 'Suppliers we owe', 'columns' => ['Supplier', 'Bill', 'Due', 'Status', 'Outstanding'],
                'rows' => array_map(fn ($r) => [$r['party'] ?? '', $r['number'] ?? '', $r['due_date'] ?? '', $r['bucket'] ?? '', (int) $r['outstanding']], $payable), 'money' => [4]],
            ['title' => 'Stock value at '.$to->format('d M Y'), 'columns' => ['Product', 'Quantity kg', 'Value'],
                'rows' => array_map(fn ($r) => [$r['name'], $r['quantity_kg'], (int) $r['value']], $stock['rows']), 'money' => [2]],
            ['title' => 'Vehicles in (inbound)', 'columns' => $gateCols, 'rows' => $inbound->map($gateRow)->values()->all(), 'money' => []],
            ['title' => 'Vehicles out (outbound)', 'columns' => $gateCols, 'rows' => $outbound->map($gateRow)->values()->all(), 'money' => []],
            ['title' => 'Production yield (closed batches)', 'columns' => $yieldCols, 'rows' => $yieldRows, 'money' => []],
            ['title' => 'Daily wages (casual labour)', 'columns' => ['Date', 'Number', 'Group / worker', 'Work', 'Workers', 'Quantity', 'Status', 'Paid'],
                'rows' => $wages->map(fn (DailyWageSheet $w) => [$w->work_date->format('d M Y'), $w->number, $w->who(), $w->category->name, $w->workers,
                    rtrim(rtrim((string) $w->quantity, '0'), '.').' '.$w->unit, DailyWageSheet::STATUSES[$w->status] ?? $w->status, $w->net_paise])->values()->all(),
                'money' => [7]],
            ['title' => 'Payroll cost by department', 'columns' => ['Department', 'Headcount', 'Gross pay', 'Employer contributions', 'Cost'],
                'rows' => array_map(fn ($r) => [$r['department'], $r['headcount'], $r['gross'], $r['employer'], $r['cost']], $payroll['rows']), 'money' => [2, 3, 4]],
            ['title' => 'Claims raised', 'columns' => ['Date', 'Number', 'Party', 'Type', 'Status', 'Claimed', 'Settled'],
                'rows' => $claims->map(fn (Claim $c) => [$c->created_at->timezone('Asia/Karachi')->format('d M Y'), $c->number, $c->party?->name, $c->type, $c->status, $c->claimed_paise, $c->settled_paise])->values()->all(), 'money' => [5, 6]],
        ];

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'generated_at' => now('Asia/Karachi')->format('d M Y H:i').' PKT',
            'headline' => $headline, 'sections' => $sections,
        ];
    }
}

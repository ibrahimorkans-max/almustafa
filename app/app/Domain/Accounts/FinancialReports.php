<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\ExpenseBudget;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\ProductCost;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherLine;
use App\Models\Hr\Advance;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Profit and loss and the management reports, read only from posted vouchers (and, for stock, the
 * stock ledger valued at the weighted-average cost in force on the day). Amounts are paise.
 *
 * Purchases are expensed when billed, so cost of goods sold is the periodic formula:
 * opening stock + purchases + direct expenses − closing stock. Every amount line carries its ledger
 * so a screen can open the ledger statement behind it.
 */
final class FinancialReports
{
    /** Root groups of the profit and loss, in the order they are shown. */
    public const PL_GROUPS = ['SALES_ACCOUNTS', 'DIRECT_INCOMES', 'PURCHASE_ACCOUNTS', 'DIRECT_EXPENSES', 'INDIRECT_EXPENSES', 'INDIRECT_INCOMES'];

    /** System ledgers that make up the payroll cost inside operating expenses. */
    public const PAYROLL_LEDGERS = ['salaries_expense', 'overtime_expense', 'bonus_expense', 'eobi_expense', 'pessi_expense'];

    /** @var array<string, string>|null group id => root group code */
    private ?array $roots = null;

    public function __construct(private readonly ChartOfAccounts $chart) {}

    /**
     * @return array{from: string, to: string, sales: array{lines: list<array<string, mixed>>, total: int}, direct_incomes: array{lines: list<array<string, mixed>>, total: int},
     *     opening_stock: int, purchases: array{lines: list<array<string, mixed>>, total: int}, direct_expenses: array{lines: list<array<string, mixed>>, total: int},
     *     closing_stock: int, cost_of_goods: int, gross_profit: int, indirect_expenses: array{lines: list<array<string, mixed>>, total: int},
     *     indirect_incomes: array{lines: list<array<string, mixed>>, total: int}, payroll_cost: int, net_profit: int}
     */
    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $by = $this->byRootGroup($from, $to);
        $opening = $this->stockValueAt($from->subDay())['total'];
        $closing = $this->stockValueAt($to)['total'];
        $cogs = $opening + $by['PURCHASE_ACCOUNTS']['total'] + $by['DIRECT_EXPENSES']['total'] - $closing;
        $gross = $by['SALES_ACCOUNTS']['total'] + $by['DIRECT_INCOMES']['total'] - $cogs;
        $payrollIds = array_map(fn (string $k) => $this->chart->system($k)->id, self::PAYROLL_LEDGERS);
        $payroll = array_sum(array_map(fn (array $l) => in_array($l['ledger_id'], $payrollIds, true) ? $l['amount'] : 0, $by['INDIRECT_EXPENSES']['lines']));

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'sales' => $by['SALES_ACCOUNTS'], 'direct_incomes' => $by['DIRECT_INCOMES'],
            'opening_stock' => $opening, 'purchases' => $by['PURCHASE_ACCOUNTS'], 'direct_expenses' => $by['DIRECT_EXPENSES'], 'closing_stock' => $closing,
            'cost_of_goods' => $cogs, 'gross_profit' => $gross,
            'indirect_expenses' => $by['INDIRECT_EXPENSES'], 'indirect_incomes' => $by['INDIRECT_INCOMES'], 'payroll_cost' => $payroll,
            'net_profit' => $gross - $by['INDIRECT_EXPENSES']['total'] + $by['INDIRECT_INCOMES']['total'],
        ];
    }

    /**
     * The profit and loss month by month (the first and last month may be partial when $to is today).
     *
     * @return list<array{month: string, sales: int, cost_of_goods: int, gross_profit: int, operating_expenses: int, other_income: int, payroll_cost: int, net_profit: int}>
     */
    public function monthly(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        for ($m = $from->startOfMonth(); $m->lessThanOrEqualTo($to); $m = $m->addMonthNoOverflow()) {
            $start = $m->greaterThan($from) ? $m : $from;
            $end = $m->endOfMonth()->startOfDay()->lessThan($to) ? $m->endOfMonth()->startOfDay() : $to;
            $pl = $this->profitAndLoss($start, $end);
            $rows[] = [
                'month' => $m->format('Y-m'), 'sales' => $pl['sales']['total'] + $pl['direct_incomes']['total'], 'cost_of_goods' => $pl['cost_of_goods'],
                'gross_profit' => $pl['gross_profit'], 'operating_expenses' => $pl['indirect_expenses']['total'], 'other_income' => $pl['indirect_incomes']['total'],
                'payroll_cost' => $pl['payroll_cost'], 'net_profit' => $pl['net_profit'],
            ];
        }

        return $rows;
    }

    /**
     * Each category's actual for the month (from its ledger) against its budget. When categories share
     * a ledger the actual is the ledger's and the row says so.
     *
     * @return list<array{category_id: string, code: string, name: string, kind: string, ledger_id: string, budget: int|null, actual: int, variance: int|null, shared_ledger: bool}>
     */
    public function budgetVsActual(string $month): array
    {
        $from = CarbonImmutable::parse($month.'-01', 'Asia/Karachi');
        $to = $from->endOfMonth()->startOfDay();
        $movement = $this->movements($from, $to);
        $categories = ExpenseCategory::query()->where('status', 'active')->orderBy('kind')->orderBy('name')->get();
        $sharing = $categories->countBy('ledger_account_id');
        $budgets = ExpenseBudget::query()->where('month', $month)->pluck('amount_paise', 'expense_category_id');
        $rows = [];
        foreach ($categories as $c) {
            $m = $movement[$c->ledger_account_id] ?? ['dr' => 0, 'cr' => 0];
            $actual = $c->kind === 'income' ? $m['cr'] - $m['dr'] : $m['dr'] - $m['cr'];
            $budget = isset($budgets[$c->id]) ? (int) $budgets[$c->id] : null;
            $rows[] = [
                'category_id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'ledger_id' => $c->ledger_account_id,
                'budget' => $budget, 'actual' => $actual, 'variance' => $budget === null ? null : $actual - $budget, 'shared_ledger' => ($sharing[$c->ledger_account_id] ?? 0) > 1,
            ];
        }

        return $rows;
    }

    /**
     * Gross pay, employer contributions and headcount by department, from finalized payroll runs.
     *
     * @return array{months: list<string>, rows: list<array{department: string, headcount: int, gross: int, employer: int, cost: int}>, total: array{headcount: int, gross: int, employer: int, cost: int}}
     */
    public function payrollCost(string $fromMonth, string $toMonth): array
    {
        $runs = PayrollRun::query()->where('status', PayrollRun::FINALIZED)->whereBetween('month', [$fromMonth, $toMonth])->orderBy('month')->get();
        $rows = [];
        foreach ($runs as $run) {
            $items = PayrollItem::query()->where('payroll_run_id', $run->id)->where('calculation_no', $run->calculation_no)->get();
            foreach ($items as $item) {
                $dept = (string) ($item->employee['department'] ?? '—') ?: '—';
                $rows[$dept] ??= ['department' => $dept, 'employees' => [], 'gross' => 0, 'employer' => 0];
                $rows[$dept]['employees'][$item->employee_id] = true;
                $rows[$dept]['gross'] += $item->gross_paise;
                $rows[$dept]['employer'] += $item->employer_paise;
            }
        }
        ksort($rows);
        $out = array_values(array_map(fn (array $r) => ['department' => $r['department'], 'headcount' => count($r['employees']), 'gross' => $r['gross'],
            'employer' => $r['employer'], 'cost' => $r['gross'] + $r['employer']], $rows));

        return [
            'months' => $runs->pluck('month')->all(), 'rows' => $out,
            'total' => ['headcount' => array_sum(array_column($out, 'headcount')), 'gross' => array_sum(array_column($out, 'gross')),
                'employer' => array_sum(array_column($out, 'employer')), 'cost' => array_sum(array_column($out, 'cost'))],
        ];
    }

    /**
     * Money in and out of the cash and bank ledgers, by what it was for. Transfers between cash and
     * bank (contra) are left out of the flows; they change nothing in total.
     *
     * @return array{opening: int, inflows: array<string, int>, outflows: array<string, int>, closing: int}
     */
    public function cashFlow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cashIds = LedgerAccount::query()->whereIn('kind', ['cash', 'bank'])->pluck('id')->all();
        $opening = (int) VoucherLine::query()->whereIn('ledger_account_id', $cashIds)->where('voucher_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(dr_paise), 0) - COALESCE(SUM(cr_paise), 0) AS bal')->value('bal');
        $lines = VoucherLine::query()->whereIn('ledger_account_id', $cashIds)
            ->whereBetween('voucher_date', [$from->toDateString(), $to->toDateString()])->get(['voucher_id', 'dr_paise', 'cr_paise']);
        $vouchers = Voucher::query()->whereIn('id', $lines->pluck('voucher_id')->unique())->get(['id', 'type', 'source_type'])->keyBy('id');
        $in = [];
        $out = [];
        foreach ($lines->groupBy('voucher_id') as $voucherId => $group) {
            $v = $vouchers[$voucherId] ?? null;
            $net = (int) $group->sum('dr_paise') - (int) $group->sum('cr_paise');
            if ($v === null || $v->type === 'contra' || $net === 0) {
                continue;
            }
            $label = self::flowLabel($v, $net > 0);
            if ($net > 0) {
                $in[$label] = ($in[$label] ?? 0) + $net;
            } else {
                $out[$label] = ($out[$label] ?? 0) - $net;
            }
        }
        arsort($in);
        arsort($out);

        return ['opening' => $opening, 'inflows' => $in, 'outflows' => $out, 'closing' => $opening + array_sum($in) - array_sum($out)];
    }

    /**
     * What the mill owes its staff, and what staff owe the mill, on a date.
     *
     * @return array{salaries_payable: int, claims_payable: int, advances: list<array{advance_id: string, number: string|null, employee: string, balance: int}>, advances_total: int}
     */
    public function staffBalances(CarbonImmutable $asOf): array
    {
        $balance = fn (string $key) => $this->balanceAt($this->chart->system($key), $asOf);
        $advances = [];
        foreach (Advance::query()->with('employee.person')->where('status', Advance::DISBURSED)->get() as $a) {
            $left = $a->balancePaise();
            if ($left > 0) {
                $advances[] = ['advance_id' => $a->id, 'number' => $a->number, 'employee' => "{$a->employee->code} {$a->employee->name()}", 'balance' => $left];
            }
        }
        usort($advances, fn (array $x, array $y) => $y['balance'] <=> $x['balance']);

        return [
            'salaries_payable' => -$balance('salaries_payable'), 'claims_payable' => -$balance('staff_claims_payable'),
            'advances' => $advances, 'advances_total' => array_sum(array_column($advances, 'balance')),
        ];
    }

    /**
     * Milling cost per maund: the period's direct and operating expenses (purchases left out, so it is
     * the cost of turning paddy into rice) over the maunds of paddy issued to milling.
     *
     * @return array{expenses: int, maunds: string, per_maund: int|null}
     */
    public function costPerMaund(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $by = $this->byRootGroup($from, $to);
        $expenses = $by['DIRECT_EXPENSES']['total'] + $by['INDIRECT_EXPENSES']['total'];
        [$start, $end] = [$from->startOfDay()->utc(), $to->endOfDay()->utc()];
        $kg = abs((float) StockEntry::query()->where('movement', StockEntry::PRODUCTION_ISSUE)->whereBetween('occurred_at', [$start, $end])->sum('quantity_kg'));
        $maunds = bcdiv(number_format($kg, 3, '.', ''), '40', 3);

        return ['expenses' => $expenses, 'maunds' => $maunds, 'per_maund' => bccomp($maunds, '0', 3) > 0 ? (int) round($expenses / (float) $maunds) : null];
    }

    /**
     * Stock on hand at the end of $date (PKT), valued at each product's average cost in force then.
     *
     * @return array{rows: list<array{product_id: string, name: string, quantity_kg: string, avg_paise_per_kg: string, value: int}>, total: int}
     */
    public function stockValueAt(CarbonImmutable $date): array
    {
        $end = $date->setTimezone('Asia/Karachi')->endOfDay()->utc();
        $quantities = StockEntry::query()->toBase()->where('occurred_at', '<=', $end)->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_kg) AS kg')->pluck('kg', 'product_id');
        $names = Product::query()->whereIn('id', $quantities->keys())->pluck('name', 'id');
        $rows = [];
        foreach ($quantities as $productId => $kg) {
            $kg = number_format((float) $kg, 3, '.', '');
            if (bccomp($kg, '0', 3) <= 0) {
                continue;
            }
            $avg = (string) (ProductCost::query()->where('product_id', $productId)->where('created_at', '<=', $end)
                ->latest('created_at')->orderByDesc('id')->value('avg_paise_per_kg') ?? '0.0000');
            $rows[] = ['product_id' => (string) $productId, 'name' => (string) ($names[$productId] ?? '?'), 'quantity_kg' => $kg, 'avg_paise_per_kg' => $avg,
                'value' => (int) bcadd(bcmul($kg, $avg, 4), '0.5', 0)];
        }

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'value'))];
    }

    /** @return array<string, array{lines: list<array{ledger_id: string, code: string, name: string, amount: int}>, total: int}> */
    private function byRootGroup(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = array_fill_keys(self::PL_GROUPS, ['lines' => [], 'total' => 0]);
        $movement = $this->movements($from, $to);
        if ($movement === []) {
            return $out;
        }
        $ledgers = LedgerAccount::query()->whereIn('id', array_keys($movement))->orderBy('code')->get();
        $roots = $this->roots();
        foreach ($ledgers as $l) {
            $root = $roots[$l->group_id] ?? null;
            if ($root === null || ! isset($out[$root])) {
                continue;
            }
            $m = $movement[$l->id];
            // Income groups are shown credit-positive, expense groups debit-positive.
            $amount = in_array($root, ['SALES_ACCOUNTS', 'DIRECT_INCOMES', 'INDIRECT_INCOMES'], true) ? $m['cr'] - $m['dr'] : $m['dr'] - $m['cr'];
            if ($amount === 0) {
                continue;
            }
            $out[$root]['lines'][] = ['ledger_id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'amount' => $amount];
            $out[$root]['total'] += $amount;
        }

        return $out;
    }

    /** @return array<string, array{dr: int, cr: int}> ledger id => sums for the period */
    private function movements(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return VoucherLine::query()->toBase()->whereBetween('voucher_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('ledger_account_id')->selectRaw('ledger_account_id, COALESCE(SUM(dr_paise), 0) AS dr, COALESCE(SUM(cr_paise), 0) AS cr')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->ledger_account_id => ['dr' => (int) $r->dr, 'cr' => (int) $r->cr]])->all();
    }

    private function balanceAt(LedgerAccount $ledger, CarbonImmutable $asOf): int
    {
        return (int) VoucherLine::query()->where('ledger_account_id', $ledger->id)->where('voucher_date', '<=', $asOf->toDateString())
            ->selectRaw('COALESCE(SUM(dr_paise), 0) - COALESCE(SUM(cr_paise), 0) AS bal')->value('bal');
    }

    /** @return array<string, string> */
    private function roots(): array
    {
        if ($this->roots !== null) {
            return $this->roots;
        }
        /** @var Collection<string, AccountGroup> $groups */
        $groups = AccountGroup::query()->get()->keyBy('id');
        $this->roots = [];
        foreach ($groups as $id => $g) {
            $root = $g;
            for ($i = 0; $root->parent_id !== null && isset($groups[$root->parent_id]) && $i < 20; $i++) {
                $root = $groups[$root->parent_id];
            }
            $this->roots[(string) $id] = $root->code;
        }

        return $this->roots;
    }

    private static function flowLabel(Voucher $v, bool $in): string
    {
        return match ($v->source_type) {
            'payment' => 'Paid to suppliers',
            'payroll_payment', 'payroll_bank_file' => 'Salaries paid',
            'advance' => $in ? 'Advances repaid by staff' : 'Advances to staff',
            'reimbursement' => 'Staff claims paid',
            'expense' => $in ? 'Other income' : 'Expenses',
            'sales_bill' => 'Cash sales',
            default => match ($v->type) {
                'receipt' => 'Received from customers and others',
                'payment' => 'Other payments',
                default => $in ? 'Other money in' : 'Other money out',
            },
        };
    }
}

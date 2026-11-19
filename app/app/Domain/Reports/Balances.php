<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Hr\SalaryVouchers;
use App\Models\Accounts\LedgerAccount;
use App\Models\Hr\Advance;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\SalaryVoucher;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Sales\QuickBill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The balance screens, read from the books and the stock ledger (never typed):
 *  - parties: every party's balance (they owe the mill, or the mill owes them), the last money in and out, unpaid bills;
 *  - employees: the month's salary, what is paid of it, what is still due, and the advances / loans outstanding;
 *  - stock: per product (paddy, rice, by-products …) the opening stock, what came in, what went out and the closing
 *    stock for any dates, in kg and maund, per godown or all together.
 */
final class Balances
{
    /** Case-insensitive LIKE on both databases (PostgreSQL: ilike; MariaDB: like, already case-insensitive). */
    public static function like(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * @return list<array{party_id: string, name: string, code: string|null, phone: string|null, balance_paise: int, last_in: string|null, last_out: string|null, unpaid_bills: int}>
     *                                                                                                                                                                                balance > 0: the party owes the mill; < 0: the mill owes the party
     */
    public function parties(?string $search = null, string $show = 'all', ?string $asOf = null): array
    {
        $asOf ??= now('Asia/Karachi')->toDateString();
        $sums = DB::table('voucher_lines')->where('voucher_date', '<=', $asOf)
            ->selectRaw('ledger_account_id, SUM(dr_paise) AS dr, SUM(cr_paise) AS cr, '
                .'MAX(CASE WHEN cr_paise > 0 THEN voucher_date END) AS last_in, MAX(CASE WHEN dr_paise > 0 THEN voucher_date END) AS last_out')
            ->groupBy('ledger_account_id');
        $rows = LedgerAccount::query()->where('ledger_accounts.kind', 'party')->whereNotNull('ledger_accounts.party_id')
            ->join('parties', 'parties.id', '=', 'ledger_accounts.party_id')
            ->leftJoinSub($sums, 's', 's.ledger_account_id', '=', 'ledger_accounts.id')
            ->when(filled($search), fn ($q) => $q->where(fn ($w) => $w->where('parties.name', self::like(), "%{$search}%")
                ->orWhere('parties.code', self::like(), "%{$search}%")->orWhere('parties.phone', 'like', "%{$search}%")))
            ->orderBy('parties.name')
            ->get(['ledger_accounts.party_id', 'parties.name', 'parties.code', 'parties.phone', 's.dr', 's.cr', 's.last_in', 's.last_out']);

        $unpaid = QuickBill::query()->where('status', QuickBill::POSTED)->whereNotNull('party_id')->whereRaw('paid_paise < abs(net_paise)')
            ->selectRaw('party_id, COUNT(*) AS n')->groupBy('party_id')->pluck('n', 'party_id');

        $out = [];
        foreach ($rows as $r) {
            $balance = (int) $r->getAttribute('dr') - (int) $r->getAttribute('cr');
            if (($show === 'owe_us' && $balance <= 0) || ($show === 'we_owe' && $balance >= 0) || ($show === 'open' && $balance === 0)) {
                continue;
            }
            $out[] = [
                'party_id' => (string) $r->getAttribute('party_id'), 'name' => (string) $r->getAttribute('name'), 'code' => $r->getAttribute('code'),
                'phone' => $r->getAttribute('phone'), 'balance_paise' => $balance,
                'last_in' => $r->getAttribute('last_in'), 'last_out' => $r->getAttribute('last_out'),
                'unpaid_bills' => (int) ($unpaid[$r->getAttribute('party_id')] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{employee_id: string, code: string, name: string, basis: string|null, salary_paise: int, paid_paise: int, due_paise: int, paid: bool, advance_paise: int, last_paid: string|null}>
     */
    public function employees(string $month, ?string $search = null): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Karachi')->endOfMonth();
        $employees = Employee::query()->with('person')->whereIn('status', ['active', 'on_notice'])
            ->where('joining_date', '<=', $end->toDateString())
            ->when(filled($search), fn ($q) => $q->where(fn ($w) => $w->where('code', self::like(), "%{$search}%")
                ->orWhereHas('person', fn ($p) => $p->where('full_name', self::like(), "%{$search}%"))))
            ->orderBy('code')->get();
        $ids = $employees->pluck('id');
        $vouchers = SalaryVoucher::query()->whereIn('employee_id', $ids)->where('kind', 'salary')->where('month', $month)->get()->groupBy('employee_id');
        $payroll = PayrollItem::query()->whereIn('employee_id', $ids)
            ->whereHas('run', fn ($q) => $q->where('month', $month)->where('status', PayrollRun::FINALIZED))->get()->groupBy('employee_id');
        $lastPaid = SalaryVoucher::query()->whereIn('employee_id', $ids)->selectRaw('employee_id, MAX(paid_at) AS at')->groupBy('employee_id')->pluck('at', 'employee_id');
        $advances = Advance::query()->whereIn('employee_id', $ids)->where('status', Advance::DISBURSED)->get()->groupBy('employee_id');

        $out = [];
        foreach ($employees as $e) {
            $structure = SalaryVouchers::structureOn($e, $end);
            $salary = $structure?->totalPaise('earning') ?? 0;
            $byVoucher = (int) ($vouchers[$e->id] ?? collect())->sum('gross_paise');
            $byPayroll = (int) ($payroll[$e->id] ?? collect())->sum('gross_paise');
            $paid = $byVoucher + $byPayroll;
            $isPaid = $paid > 0;
            $out[] = [
                'employee_id' => $e->id, 'code' => $e->code, 'name' => $e->name(), 'basis' => $structure?->pay_basis,
                'salary_paise' => $salary, 'paid_paise' => $paid,
                'due_paise' => $isPaid || $structure?->pay_basis !== 'monthly' ? 0 : $salary,
                'paid' => $isPaid,
                'advance_paise' => (int) ($advances[$e->id] ?? collect())->sum(fn (Advance $a) => $a->balancePaise()),
                'last_paid' => isset($lastPaid[$e->id]) ? CarbonImmutable::parse((string) $lastPaid[$e->id])->timezone('Asia/Karachi')->format('d M Y') : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{product: string, category: string, godown: string|null, opening_kg: string, in_kg: string, out_kg: string, closing_kg: string}>
     */
    public function stock(string $from, string $to, ?string $category = null, ?string $godownId = null, bool $byGodown = false): array
    {
        $start = CarbonImmutable::parse($from, 'Asia/Karachi')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Karachi')->endOfDay()->utc();
        $group = $byGodown ? ['product_id', 'godown_id'] : ['product_id'];
        $rows = DB::table('stock_ledger')
            ->when($godownId !== null, fn ($q) => $q->where('godown_id', $godownId))
            ->where('occurred_at', '<=', $end)
            ->selectRaw(implode(', ', $group).', '
                .'SUM(CASE WHEN occurred_at < ? THEN quantity_kg ELSE 0 END) AS opening, '
                .'SUM(CASE WHEN occurred_at >= ? AND quantity_kg > 0 THEN quantity_kg ELSE 0 END) AS qin, '
                .'SUM(CASE WHEN occurred_at >= ? AND quantity_kg < 0 THEN -quantity_kg ELSE 0 END) AS qout', [$start, $start, $start])
            ->groupBy($group)->get();
        $products = Product::query()->whereIn('id', $rows->pluck('product_id')->unique())->get()->keyBy('id');
        $godowns = $byGodown ? Godown::query()->whereIn('id', $rows->pluck('godown_id')->unique())->get()->keyBy('id') : collect();

        $out = [];
        foreach ($rows as $r) {
            $p = $products[$r->product_id] ?? null;
            if ($p === null || ($category !== null && $p->category !== $category)) {
                continue;
            }
            $opening = bcadd((string) $r->opening, '0', 3);
            $in = bcadd((string) $r->qin, '0', 3);
            $outKg = bcadd((string) $r->qout, '0', 3);
            if (bccomp($opening, '0', 3) === 0 && bccomp($in, '0', 3) === 0 && bccomp($outKg, '0', 3) === 0) {
                continue;
            }
            $out[] = [
                'product' => $p->name, 'category' => $p->category,
                'godown' => $byGodown ? ($godowns[$r->godown_id]->name ?? null) : null,
                'opening_kg' => $opening, 'in_kg' => $in, 'out_kg' => $outKg,
                'closing_kg' => bcsub(bcadd($opening, $in, 3), $outKg, 3),
            ];
        }
        usort($out, fn ($a, $b) => [array_search($a['category'], Product::CATEGORIES, true), $a['product'], $a['godown']]
            <=> [array_search($b['category'], Product::CATEGORIES, true), $b['product'], $b['godown']]);

        return $out;
    }
}

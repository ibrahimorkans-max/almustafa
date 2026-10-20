<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Accounts\AccountReports;
use App\Domain\Accounts\StockValuation;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Gate\VehicleRegister;
use App\Domain\Hr\Attendance\AttendanceRegister;
use App\Domain\Hr\Payroll\StatutoryReports;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tax\SalesTaxReturns;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Support\Export\XlsxWorkbook;
use App\Support\Values\Money;
use App\Support\Values\PakistanIds;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Every report of the system in one list, by category, each turned into printable tables: used for
 * the PDF button on each report screen and for "All reports" (every report in one PDF / Excel).
 *
 * A report is [category, title, period, who may see it]. The period says how the chosen dates are used:
 *   range  from – to;   as_of  on the "to" date;   month  every calendar month in the range;   none  as it is now.
 * A built report is a list of parts: {title, subtitle, columns, rows, right (numeric columns), lines (notes)}.
 */
final class ReportCatalog
{
    public const CATEGORIES = [
        'summary' => 'Summary',
        'accounts' => 'Accounts and finance',
        'trade' => 'Purchases and sales',
        'procurement' => 'Procurement and lab',
        'gate' => 'Gate and vehicles',
        'stock' => 'Stock and production',
        'tax' => 'Tax',
        'hr' => 'HR and payroll',
    ];

    /** key => [category, title, period, permissions (any of)] */
    public const REPORTS = [
        'business' => ['summary', 'Complete business report (key figures)', 'range', ['report.view']],
        'pl' => ['accounts', 'Profit and loss', 'range', ['ledger.view']],
        'monthly' => ['accounts', 'Month by month', 'range', ['ledger.view']],
        'cashflow' => ['accounts', 'Cash flow', 'range', ['ledger.view']],
        'budget' => ['accounts', 'Income and expenses against budget', 'month', ['ledger.view']],
        'payroll_cost' => ['accounts', 'Payroll cost by department', 'range', ['ledger.view']],
        'staff' => ['accounts', 'Salaries, claims and advances outstanding', 'as_of', ['ledger.view']],
        'cashbook' => ['accounts', 'Cash book (daily closing and entries)', 'range', ['ledger.view']],
        'bankbook' => ['accounts', 'Bank book (daily closing and entries)', 'range', ['bank.view', 'ledger.view']],
        'trial_balance' => ['accounts', 'Trial balance', 'as_of', ['ledger.view']],
        'day_book' => ['accounts', 'Day book (last day of the period)', 'as_of', ['ledger.view']],
        'payables' => ['accounts', 'Payables ageing', 'as_of', ['ledger.view']],
        'receivables' => ['accounts', 'Receivables ageing', 'as_of', ['ledger.view']],
        'purchase_register' => ['trade', 'Purchase register', 'range', ['report.view']],
        'sales_register' => ['trade', 'Sales register', 'range', ['report.view']],
        'settlements' => ['procurement', 'Vehicle settlements and landed cost', 'range', ['report.view', 'purchase.negotiate', 'purchase.approve']],
        'lab_results' => ['procurement', 'Lab test results against the limits', 'range', ['report.view', 'lab.test', 'lab.decide', 'purchase.approve']],
        'rejections' => ['procurement', 'Rejected lots', 'range', ['report.view', 'lab.decide', 'purchase.negotiate', 'purchase.approve']],
        'negotiations' => ['procurement', 'Negotiation history (price versions)', 'range', ['report.view', 'purchase.negotiate', 'purchase.approve']],
        'caat_analysis' => ['procurement', 'Caat analysis', 'range', ['report.view', 'purchase.negotiate', 'purchase.approve']],
        'price_variance' => ['procurement', 'Price variance: offered against accepted', 'range', ['report.view', 'purchase.approve']],
        'vehicle_register' => ['gate', 'Vehicle register (in and out, weights and amounts)', 'range', ['gate.entry', 'weighment.capture', 'lab.test', 'lab.decide']],
        'gate_register' => ['gate', 'Gate register', 'range', ['report.view']],
        'stock_value' => ['stock', 'Stock value', 'none', ['ledger.view']],
        'stock_movement' => ['stock', 'Stock movement', 'range', ['report.view']],
        'production_yield' => ['stock', 'Production yield', 'range', ['report.view']],
        'annex_c' => ['tax', 'Sales tax register (Annex-C)', 'month', ['report.tax']],
        'wht153' => ['tax', 'Income tax withheld u/s 153', 'month', ['report.tax']],
        'attendance' => ['hr', 'Attendance register', 'month', ['hr.employee.view']],
        'wages' => ['hr', 'Wages register', 'month', ['hr.payroll.run', 'hr.payroll.approve']],
        'overtime' => ['hr', 'Overtime register', 'month', ['hr.payroll.run', 'hr.payroll.approve']],
        'eobi' => ['hr', 'EOBI contributions (PR-01)', 'month', ['hr.payroll.run', 'hr.payroll.approve']],
        'pessi' => ['hr', 'PESSI contributions', 'month', ['hr.payroll.run', 'hr.payroll.approve']],
        'tax149' => ['hr', 'Income tax on salary (s. 149)', 'month', ['hr.payroll.run', 'hr.payroll.approve']],
    ];

    /** More months than this in "month" reports would make a huge file. */
    private const MAX_MONTHS = 12;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PdfRenderer $pdf,
    ) {}

    public static function allowed(LoginAccount $user, string $key): bool
    {
        if (! isset(self::REPORTS[$key])) {
            return false;
        }
        if ($key === 'business' && ! $user->hasPermission('ledger.view')) {
            return false;
        }
        foreach (self::REPORTS[$key][3] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, string>> category => [key => title], only the reports the person may see */
    public static function forUser(LoginAccount $user): array
    {
        $out = [];
        foreach (self::REPORTS as $key => [$category, $title]) {
            if (self::allowed($user, $key)) {
                $out[$category][$key] = $title;
            }
        }

        return array_intersect_key(array_replace(array_fill_keys(array_keys(self::CATEGORIES), []), $out), $out);
    }

    /**
     * One report as printable parts.
     *
     * @param  array<string, mixed>  $options  department (attendance)
     * @return list<array{title: string, subtitle: string, columns: list<string>, rows: list<list<string>>, right: list<int>, lines: list<string>}>
     */
    public function build(string $key, CarbonImmutable $from, CarbonImmutable $to, array $options = []): array
    {
        [, $title, $period] = self::REPORTS[$key] ?? throw new BusinessRuleViolation("Unknown report {$key}.");
        if ($period === 'month') {
            $parts = [];
            foreach (self::months($from, $to) as $month) {
                foreach ($this->monthly($key, $title, $month, $options) as $part) {
                    $parts[] = $part;
                }
            }

            return $parts;
        }

        try {
            return $this->ranged($key, $title, $from, $to);
        } catch (BusinessRuleViolation $e) {
            return [self::part($title, self::span($from, $to), [], [], [], [$e->getMessage()])];
        }
    }

    /** @return list<array<string, mixed>> */
    private function ranged(string $key, string $title, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $span = self::span($from, $to);
        $asOf = 'As on '.$to->format('d M Y');
        $rs = fn (?int $p) => $p === null ? '' : Money::paise($p)->grouped();

        switch ($key) {
            case 'business':
                $r = app(BusinessReport::class)->build($from, $to);
                $parts = [self::part('Key figures', $span, ['Figure', 'Value'], array_map(fn (array $h) => [$h[0], $h[1]], $r['headline']), [1])];
                foreach ($r['sections'] as $s) {
                    $parts[] = self::part($s['title'], $span, $s['columns'], array_map(fn (array $row) => array_map(
                        fn ($v, $i) => in_array($i, $s['money'], true) && is_int($v) ? $rs($v) : (string) $v, $row, array_keys($row)), $s['rows']), $s['money']);
                }

                return $parts;

            case 'pl': case 'monthly': case 'cashflow': case 'payroll_cost': case 'staff':
                $t = app(FinancialTables::class)->build($key === 'payroll_cost' ? 'payroll' : $key, ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
                $rows = array_map(fn (array $r) => [($r['style'] === 'line' ? '   ' : '').$r['label'],
                    ...array_map(fn ($c) => is_int($c) ? $rs($c) : (string) $c, $r['cells'])], $t['rows']);
                $columns = $t['columns'];
                $columns[0] = $columns[0] === '' ? 'Item' : $columns[0];

                return [self::part($t['title'], $t['subtitle'], $columns, $rows, range(1, max(1, count($columns) - 1)), $t['notes'])];

            case 'trial_balance':
                $tb = app(AccountReports::class)->trialBalance($to);
                $rows = array_map(fn (array $r) => [$r['name'], $r['group'], $r['dr'] ? $rs($r['dr']) : '', $r['cr'] ? $rs($r['cr']) : ''], $tb['rows']);
                $rows[] = ['TOTAL', '', $rs($tb['total_dr']), $rs($tb['total_cr'])];

                return [self::part($title, $asOf, ['Ledger', 'Group', 'Debit (Rs)', 'Credit (Rs)'], $rows, [2, 3])];

            case 'day_book':
                $rows = [];
                foreach (app(AccountReports::class)->dayBook($to) as $v) {
                    foreach ($v['lines'] as $i => $l) {
                        $rows[] = [$i === 0 ? $v['number'] : '', $i === 0 ? $v['type'] : '', $i === 0 ? (string) $v['narration'] : '', $l['account'],
                            $l['dr'] ? $rs($l['dr']) : '', $l['cr'] ? $rs($l['cr']) : ''];
                    }
                }

                return [self::part('Day book', $to->format('d M Y'), ['Voucher', 'Type', 'Narration', 'Account', 'Debit (Rs)', 'Credit (Rs)'], $rows, [4, 5])];

            case 'payables':
                $data = app(AccountReports::class)->payablesAgeing($to);
                $rows = array_map(fn (array $r) => [$r['number'], $r['party'], CarbonImmutable::parse($r['due_date'])->format('d M Y'), $r['bucket'],
                    $rs($r['net']), $rs($r['paid']), $rs($r['requested']), $rs($r['outstanding'])], $data);
                $rows[] = ['TOTAL', '', '', '', '', '', '', $rs(array_sum(array_column($data, 'outstanding')))];

                return [self::part($title, $asOf, ['Bill', 'Party', 'Due', 'Age', 'Bill amount', 'Paid', 'Awaiting approval', 'Outstanding'], $rows, [4, 5, 6, 7])];

            case 'receivables':
                $data = app(AccountReports::class)->receivablesAgeing($to);
                $rows = array_map(fn (array $r) => [$r['number'], $r['party'], CarbonImmutable::parse($r['due_date'])->format('d M Y'), $r['bucket'],
                    $rs($r['total']), $rs($r['received']), $rs($r['outstanding'])], $data);
                $rows[] = ['TOTAL', '', '', '', '', '', $rs(array_sum(array_column($data, 'outstanding')))];

                return [self::part($title, $asOf, ['Invoice', 'Buyer', 'Due', 'Age', 'Invoice total', 'Received', 'Outstanding'], $rows, [4, 5, 6])];

            case 'stock_value':
                $data = app(StockValuation::class)->stockValue();
                $products = Product::query()->pluck('name', 'id');
                $godowns = Godown::query()->pluck('name', 'id');
                $rows = array_map(fn (array $r) => [(string) ($products[$r['product_id']] ?? ''), (string) ($godowns[$r['godown_id']] ?? ''),
                    number_format((float) $r['quantity_kg'], 2), Money::rupeesText(((float) $r['avg_paise_per_kg']) / 100), $rs($r['value_paise'])], $data);
                $rows[] = ['TOTAL', '', '', '', $rs(array_sum(array_column($data, 'value_paise')))];

                return [self::part($title, 'Now ('.now('Asia/Karachi')->format('d M Y H:i').')', ['Product', 'Godown', 'kg', 'Average cost / kg', 'Value (Rs)'], $rows, [2, 3, 4])];

            case 'vehicle_register':
                $register = app(VehicleRegister::class);
                $parts = [];
                foreach (VehicleRegister::SIDES as $side => $sideTitle) {
                    [$start] = VehicleRegister::bounds($from);
                    [, $end] = VehicleRegister::bounds($to);
                    $entries = GateEntry::query()->with(['party', 'product', 'sauda', 'driver', 'vehicle', 'godown'])->whereNull('archived_at')->where('direction', $side)
                        ->where(fn ($q) => $q->whereBetween('arrived_at', [$start, $end])->orWhereBetween('exited_at', [$start, $end]))
                        ->orderBy('arrived_at')->get();
                    $list = $entries->map(fn (GateEntry $e) => $register->row($e))->values()->all();
                    $sums = VehicleRegister::totals($list);
                    $rows = [];
                    foreach ($list as $r) {
                        $rows[] = [$r['arrived']->format('d M H:i'), $r['exited']?->format('d M H:i') ?? 'inside', (string) $r['number'],
                            PakistanIds::formatVehicleNumber($r['vehicle']), (string) ($r['driver'] ?? ''), (string) ($r['party'] ?? ''), (string) ($r['product'] ?? ''),
                            (string) ($r['bags'] ?? ''), self::num($r['gross_kg']), self::num($r['tare_kg']), self::num($r['net_kg']), self::num($r['deduction_kg']),
                            $r['payable_kg'] !== null ? Weight::kg($r['payable_kg'])->in('maund', 2) : '', $rs($r['price']['rate_paise']),
                            $rs($r['price']['net_paise']).($r['price']['state'] === 'estimate' ? ' *' : ''), $r['status']];
                    }
                    $rows[] = ['TOTAL', '', $sums['vehicles'].' vehicles', '', '', '', '', number_format($sums['bags']), '', '', self::num($sums['net_kg']),
                        self::num($sums['deduction_kg']), Weight::kg($sums['payable_kg'])->in('maund', 2), '', $rs($sums['amount_paise']), ''];
                    $parts[] = self::part($sideTitle, $span, ['In', 'Out', 'Entry', 'Vehicle', 'Driver', $side === 'inbound' ? 'Supplier' : 'Buyer', 'Product', 'Bags',
                        'Gross kg', 'Tare kg', 'Net kg', 'Deduct kg', 'Maund', 'Rate / md', 'Amount (Rs)', 'Status'], $rows, [7, 8, 9, 10, 11, 12, 13, 14],
                        ['Net = gross − tare. Payable = net − lab deduction. Amount = payable maund × sauda rate − quality rate cut. * = estimate (bill not made yet).']);
                }

                return $parts;

            case 'cashbook': case 'bankbook':
                return app(CashBankBooks::class)->build($key === 'cashbook' ? 'cash' : 'bank', $from, $to);

            case 'settlements': case 'lab_results': case 'rejections': case 'negotiations': case 'caat_analysis': case 'price_variance':
                return app(ProcurementReports::class)->build($key, $from, $to);

            case 'purchase_register': case 'sales_register': case 'gate_register': case 'stock_movement': case 'production_yield':
                [$header, $rows] = app(OperationalReports::class)->run($key, $from, $to);
                $right = [];
                foreach ($header as $i => $h) {
                    if (preg_match('/kg|Rate|Gross|Net|cut|Round|WHT|Total|value|tax|Paid|%|Value/i', $h) && ! preg_match('/^(Status|Net payable status)$/', $h)) {
                        $right[] = $i;
                    }
                }

                return [self::part($title, $span, $header, array_map(fn (array $r) => array_map(fn ($v) => (string) $v, $r), $rows), $right)];
        }

        throw new BusinessRuleViolation("Unknown report {$key}.");
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function monthly(string $key, string $title, string $month, array $options): array
    {
        $label = CarbonImmutable::parse($month.'-01')->format('F Y');
        $rs = fn (int $p) => Money::paise($p)->grouped();
        try {
            switch ($key) {
                case 'budget':
                    $t = app(FinancialTables::class)->build('budget', ['month' => $month]);
                    $rows = array_map(fn (array $r) => [($r['style'] === 'line' ? '   ' : '').$r['label'],
                        ...array_map(fn ($c) => is_int($c) ? $rs($c) : (string) $c, $r['cells'])], $t['rows']);
                    $columns = $t['columns'];
                    $columns[0] = $columns[0] === '' ? 'Item' : $columns[0];

                    return [self::part($t['title'], $t['subtitle'], $columns, $rows, range(1, max(1, count($columns) - 1)), $t['notes'])];

                case 'annex_c':
                    $r = app(SalesTaxReturns::class)->salesRegister($month);
                    $rows = array_map(fn (array $i) => [PakistanIds::formatNtn($i['buyer_ntn']), $i['buyer_name'], $i['type'], (string) $i['number'], $i['date'],
                        (string) $i['pct'], (string) $i['quantity_kg'], $i['exempt'] ? 'Exempt' : ($i['rate_bp'] / 100).'%',
                        $rs($i['taxable']), $rs($i['sales_tax']), $rs($i['further_tax'])], $r['sales']);
                    $rows[] = ['TOTAL', '', '', '', '', '', '', '', $rs(array_sum(array_column($r['sales'], 'taxable'))),
                        $rs(array_sum(array_column($r['sales'], 'sales_tax'))), $rs(array_sum(array_column($r['sales'], 'further_tax')))];

                    return [self::part($title, $label, ['Buyer NTN / CNIC', 'Buyer', 'Document', 'Number', 'Date', 'HS code', 'Quantity kg', 'Rate',
                        'Value excl. tax', 'Sales tax', 'Further tax'], $rows, [6, 8, 9, 10], $r['locked'] ? ['The period is locked.'] : [])];

                case 'wht153':
                    $data = app(SalesTaxReturns::class)->withholdingRegister($month);
                    $rows = array_map(fn (array $i) => [(string) $i['supplier'], PakistanIds::formatNtn($i['ntn']), PakistanIds::formatCnic($i['cnic']),
                        $i['filer'] ? 'Yes' : 'No', (string) $i['bill'], (string) $i['date'], $rs($i['gross']), $rs($i['base']), ($i['rate_bp'] / 100).'%', $rs($i['tax'])], $data);
                    $rows[] = ['TOTAL', '', '', '', '', '', '', '', '', $rs(array_sum(array_column($data, 'tax')))];

                    return [self::part($title, $label, ['Supplier', 'NTN', 'CNIC', 'Filer', 'Bill', 'Date', 'Value of goods', 'Taxable', 'Rate', 'Tax withheld'], $rows, [6, 7, 8, 9])];

                case 'attendance':
                    $m = app(AttendanceRegister::class)->month($month, isset($options['department']) && is_string($options['department']) ? $options['department'] : null);
                    $columns = ['Code', 'Name', ...array_map(fn ($d) => substr($d, 8, 2), $m['dates']), 'P', 'PL', 'UL', 'A', 'OT min'];
                    $rows = array_map(fn (array $r) => [$r['code'], $r['name'], ...array_map('strval', $r['days']), (string) $r['totals']['present'],
                        (string) $r['totals']['paid_leave'], (string) $r['totals']['unpaid_leave'], (string) $r['totals']['absent'], (string) $r['totals']['overtime_minutes']], $m['rows']);

                    return [self::part($title, $label, $columns, $rows, [], ['P present · PL paid leave · UL unpaid leave · A absent · OT overtime minutes'])];

                default: // statutory payroll reports
                    $data = app(StatutoryReports::class)->build($key, $month);

                    return [self::part($data['title'], $label, $data['header'],
                        array_map(fn (array $r) => array_map(fn ($v) => is_float($v) ? number_format($v, 2) : (string) $v, $r), $data['rows']), [])];
            }
        } catch (BusinessRuleViolation $e) {
            return [self::part($title, $label, [], [], [], [$e->getMessage()])];
        }
    }

    /**
     * Several reports in one PDF: a cover with the contents by category, then each report from a new page.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $options
     */
    public function pdf(array $keys, CarbonImmutable $from, CarbonImmutable $to, array $options = [], ?string $title = null): string
    {
        $book = $this->book($keys, $from, $to, $options);

        return $this->pdf->render('reports.book', [
            'title' => $title ?? (count($keys) === 1 ? self::REPORTS[$keys[0]][1] : 'All reports'),
            'book' => $book, 'from' => $from, 'to' => $to, 'cover' => count($keys) > 1,
        ], $this->entity(), 'A4-L');
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $options
     */
    public function excel(array $keys, CarbonImmutable $from, CarbonImmutable $to, array $options = []): string
    {
        $book = new XlsxWorkbook;
        $used = [];
        foreach ($this->book($keys, $from, $to, $options) as $category) {
            foreach ($category['reports'] as $report) {
                foreach ($report['parts'] as $part) {
                    $name = mb_substr($part['title'].($part['subtitle'] !== '' && count($report['parts']) > 1 ? ' '.$part['subtitle'] : ''), 0, 28);
                    $base = $name;
                    for ($n = 2; isset($used[mb_strtolower($name)]); $n++) {
                        $name = mb_substr($base, 0, 25).' '.$n;
                    }
                    $used[mb_strtolower($name)] = true;
                    $rows = $part['rows'] !== [] ? $part['rows'] : array_map(fn ($l) => [$l], $part['lines'] ?: ['Nothing to show.']);
                    $book->addSheet($name, $part['columns'] !== [] ? $part['columns'] : ['Note'], $rows);
                }
            }
        }

        return $book->toString();
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $options
     * @return list<array{category: string, reports: list<array{key: string, title: string, parts: list<array<string, mixed>>}>}>
     */
    private function book(array $keys, CarbonImmutable $from, CarbonImmutable $to, array $options): array
    {
        $out = [];
        foreach (self::CATEGORIES as $category => $label) {
            $reports = [];
            foreach (self::REPORTS as $key => [$cat, $title]) {
                if ($cat !== $category || ! in_array($key, $keys, true)) {
                    continue;
                }
                try {
                    $parts = $this->build($key, $from, $to, $options);
                } catch (Throwable $e) {
                    report($e);
                    $parts = [self::part($title, self::span($from, $to), [], [], [], ['This report could not be made: '.$e->getMessage()])];
                }
                $reports[] = ['key' => $key, 'title' => $title, 'parts' => $parts];
            }
            if ($reports !== []) {
                $out[] = ['category' => $label, 'reports' => $reports];
            }
        }

        return $out;
    }

    /** @return list<string> Y-m of every month the range touches (at most 12, the latest) */
    public static function months(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($m = $from->startOfMonth(); $m->lessThanOrEqualTo($to); $m = $m->addMonthNoOverflow()) {
            $months[] = $m->format('Y-m');
        }

        return array_slice($months, -self::MAX_MONTHS);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<int>  $right
     * @param  list<string>  $lines
     * @return array{title: string, subtitle: string, columns: list<string>, rows: list<list<string>>, right: list<int>, lines: list<string>}
     */
    private static function part(string $title, string $subtitle, array $columns, array $rows, array $right = [], array $lines = []): array
    {
        return compact('title', 'subtitle', 'columns', 'rows', 'right', 'lines');
    }

    private static function span(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->isSameDay($to) ? $from->format('d M Y') : $from->format('d M Y').' – '.$to->format('d M Y');
    }

    private static function num(?string $kg): string
    {
        return $kg === null ? '' : number_format((float) $kg);
    }

    private function entity(): ?Entity
    {
        $id = $this->tenant->entityId();
        if ($id === null) {
            $me = auth('web')->user();
            $id = $me instanceof LoginAccount ? $me->entity_id : null;
        }

        return $id === null ? null : $this->tenant->runAsSystem(fn () => Entity::query()->find($id));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\PurchaseBill;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\StockEntry;
use App\Models\Production\ProductionBatch;
use App\Models\Sales\SalesBill;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Registers and summaries straight from the records (never re-typed). Each report returns
 * [columns, rows] so the same data feeds the screen and the CSV export. Money in rupees with two
 * decimals, weights in kg with three. Exports are audited.
 */
final class OperationalReports
{
    /** name => label */
    public const REPORTS = [
        'purchase_register' => 'Purchase register', 'sales_register' => 'Sales register', 'gate_register' => 'Gate register',
        'stock_movement' => 'Stock movement', 'production_yield' => 'Production yield',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{0: list<string>, 1: list<list<string|int|float|null>>} */
    public function run(string $report, CarbonInterface $from, CarbonInterface $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $rs = fn (int $p) => number_format($p / 100, 2, '.', '');
        // Whole PKT days, as UTC instants (timestamps are stored in UTC; the query builder does not convert).
        $start = CarbonImmutable::parse($f, 'Asia/Karachi')->startOfDay()->utc();
        $end = CarbonImmutable::parse($t, 'Asia/Karachi')->endOfDay()->utc();

        return match ($report) {
            'purchase_register' => [
                ['Date', 'Voucher', 'Supplier', 'Slip', 'Sauda', 'Payable kg', 'Rate / maund', 'Gross', 'Rate cut', 'Round off', 'WHT s.153', 'Net payable', 'Status'],
                PurchaseBill::query()->with('party')->whereBetween('bill_date', [$f, $t])->where('status', '<>', PurchaseBill::DRAFT)
                    ->orderBy('bill_date')->orderBy('number')->get()
                    ->map(fn (PurchaseBill $b) => [
                        $b->bill_date->toDateString(), $b->number, $b->party->name, $b->snapshot['gate_entry_number'] ?? '', $b->snapshot['sauda_number'] ?? '',
                        $b->payable_kg, $rs($b->rate_paise_per_maund), $rs($b->gross_paise), $rs($b->rate_cut_paise), $rs($b->round_off_paise),
                        $rs($b->wht_paise), $rs($b->net_paise), $b->status,
                    ])->all(),
            ],
            'sales_register' => [
                ['Date', 'Invoice', 'Type', 'Buyer', 'Buyer NTN', 'Buyer STRN', 'PCT', 'Quantity kg', 'Value excl. tax', 'Sales tax', 'Further tax', 'Round off', 'Total', 'Status'],
                SalesBill::query()->with('party')->whereBetween('bill_date', [$f, $t])->where('status', '<>', SalesBill::DRAFT)
                    ->orderBy('bill_date')->orderBy('number')->get()
                    ->map(fn (SalesBill $b) => [
                        $b->bill_date->toDateString(), $b->number, $b->isSalesTaxInvoice() ? 'Sales tax invoice' : 'Invoice', $b->party->name,
                        PakistanIds::formatNtn($b->buyer_ntn), $b->buyer_strn, $b->pct_code,
                        $b->quantity_kg, $rs($b->taxable_paise), $rs($b->sales_tax_paise), $rs($b->further_tax_paise),
                        $rs($b->round_off_paise), $rs($b->total_paise), $b->status,
                    ])->all(),
            ],
            'gate_register' => [
                ['Arrived', 'Entry', 'Vehicle', 'Direction', 'Party', 'Gross kg', 'Tare kg', 'Net kg', 'Payable kg', 'Status', 'Exited', 'Flags'],
                GateEntry::query()->with('party')->whereBetween('arrived_at', [$start, $end])
                    ->orderBy('arrived_at')->get()
                    ->map(fn (GateEntry $e) => [
                        $e->arrived_at->timezone('Asia/Karachi')->format('Y-m-d H:i'), $e->number, $e->registration_no, $e->direction,
                        $e->party?->name, $e->gross_kg, $e->tare_kg, $e->net_kg, $e->payable_kg, $e->status,
                        $e->exited_at?->timezone('Asia/Karachi')->format('Y-m-d H:i'), implode(' ', $e->flags ?? []),
                    ])->all(),
            ],
            'stock_movement' => [
                ['When', 'Product', 'Godown', 'Movement', 'kg', 'Reference'],
                StockEntry::query()->with(['product', 'godown'])->whereBetween('occurred_at', [$start, $end])
                    ->orderBy('occurred_at')->get()
                    ->map(fn (StockEntry $s) => [
                        $s->occurred_at->timezone('Asia/Karachi')->format('Y-m-d H:i'), $s->product->name, $s->godown->name, $s->movement, $s->quantity_kg, $s->note,
                    ])->all(),
            ],
            'production_yield' => [
                ['Batch', 'Paddy', 'Opened', 'Closed', 'Input kg', 'Output kg', 'Unaccounted kg', 'Unaccounted %', 'Outputs outside standard'],
                ProductionBatch::query()->with('paddy')->where('status', ProductionBatch::CLOSED)
                    ->whereBetween('closed_at', [$start, $end])->orderBy('closed_at')->get()
                    ->map(fn (ProductionBatch $b) => [
                        $b->number, $b->paddy->name, $b->opened_at->toDateString(), $b->closed_at?->toDateString(), $b->input_kg, $b->output_kg,
                        $b->yield_snapshot['unaccounted_kg'] ?? null, number_format(((int) ($b->yield_snapshot['unaccounted_bp'] ?? 0)) / 100, 2),
                        self::outOfRange((array) ($b->yield_snapshot['outturn'] ?? [])),
                    ])->all(),
            ],
            default => throw new BusinessRuleViolation("Unknown report {$report}."),
        };
    }

    /** @param  array<array-key, mixed>  $outturn */
    private static function outOfRange(array $outturn): string
    {
        $names = [];
        foreach ($outturn as $row) {
            if (is_array($row) && ($row['out_of_range'] ?? false)) {
                $names[] = (string) ($row['product'] ?? '');
            }
        }

        return implode(', ', $names);
    }

    public function csv(string $report, CarbonInterface $from, CarbonInterface $to): string
    {
        [$columns, $rows] = $this->run($report, $from, $to);
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot build the file.');
        fputcsv($out, $columns, escape: '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v, $row), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->record('report.exported', null, null, null, ['report' => $report, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => count($rows)]);

        return $csv;
    }
}

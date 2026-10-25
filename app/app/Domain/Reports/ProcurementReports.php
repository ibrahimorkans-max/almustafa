<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Accounts\StockValuation;
use App\Domain\Gate\GateService;
use App\Domain\Gate\LabStandards;
use App\Models\Accounts\PurchaseBill;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use App\Support\Values\PakistanIds;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Procurement and lab reports over the paddy vehicles that arrived in a period (archived ones left out):
 *   settlements      the Caat, payable weight, settled price, purchase value, charges and landed cost
 *   lab_results      every lab test against its standard and grace limit, and the decision
 *   rejections       rejected lots: what failed, why, held where, what became of them
 *   negotiations     every price version with its reason, who proposed and who decided
 *   caat_analysis    the Caat by vehicle and by supplier
 *   price_variance   the offered (sauda) price against the last accepted price, and the difference
 * Each returns parts in the ReportCatalog format (title, subtitle, columns, rows, right, lines).
 */
final class ProcurementReports
{
    public const REPORTS = [
        'settlements' => 'Vehicle settlements and landed cost',
        'lab_results' => 'Lab test results against the limits',
        'rejections' => 'Rejected lots',
        'negotiations' => 'Negotiation history (price versions)',
        'caat_analysis' => 'Caat analysis',
        'price_variance' => 'Price variance: offered against accepted',
    ];

    public function __construct(
        private readonly GateService $gate,
        private readonly StockValuation $valuation,
    ) {}

    /** @return list<array{title: string, subtitle: string, columns: list<string>, rows: list<list<string>>, right: list<int>, lines: list<string>}> */
    public function build(string $key, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $span = $from->isSameDay($to) ? $from->format('d M Y') : $from->format('d M Y').' – '.$to->format('d M Y');
        $entries = $this->vehicles($from, $to);

        return match ($key) {
            'settlements' => [$this->settlements($entries, $span)],
            'lab_results' => [$this->labResults($entries, $span)],
            'rejections' => [$this->rejections($entries, $span)],
            'negotiations' => [$this->negotiations($entries, $span)],
            'caat_analysis' => $this->caatAnalysis($entries, $span),
            'price_variance' => [$this->priceVariance($entries, $span)],
            default => throw new \InvalidArgumentException("Unknown report {$key}."),
        };
    }

    /** @return Collection<int, GateEntry> */
    private function vehicles(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $start = CarbonImmutable::parse($from->toDateString(), 'Asia/Karachi')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to->toDateString(), 'Asia/Karachi')->endOfDay()->utc();

        return GateEntry::query()->with(['party', 'product', 'sauda'])->where('direction', 'inbound')->whereNull('archived_at')
            ->whereBetween('arrived_at', [$start, $end])->orderBy('arrived_at')->get();
    }

    /** @return array<string, mixed>|null the approved settlement in force */
    private function settlement(GateEntry $e): ?array
    {
        if (! $this->gate->settlementApproved($e)) {
            return null;
        }

        return GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'settlement_approved')->newestFirst()->value('data');
    }

    /**
     * @param  Collection<int, GateEntry>  $entries
     * @return array<string, mixed>
     */
    private function settlements(Collection $entries, string $span): array
    {
        $rows = [];
        $sum = ['net' => '0', 'caat' => '0', 'payable' => '0', 'value' => 0, 'charges' => 0, 'landed' => 0];
        foreach ($entries as $e) {
            $s = $this->settlement($e);
            if ($s === null) {
                continue;
            }
            $bill = PurchaseBill::query()->where('gate_entry_id', $e->id)->where('status', '<>', PurchaseBill::CANCELLED)->first();
            $cost = $this->valuation->landedCostOf($e->id);
            $value = $bill !== null ? $cost['purchase_paise']
                : (int) round((float) bcmul(bcdiv((string) $s['payable_kg'], '40', 6), (string) ((int) $s['price_paise_per_maund'] - (int) ($s['rate_cut_paise_per_maund'] ?? 0)), 2));
            $landed = $value + $cost['charges_paise'];
            $maund = bcdiv((string) $s['payable_kg'], '40', 4);
            $rows[] = [$this->date($e), (string) $e->number, PakistanIds::formatVehicleNumber($e->registration_no), (string) ($e->party->name ?? ''),
                self::kg($s['net_kg']), self::kg($s['waste_caat_kg']), self::kg($s['per_maund_caat_kg']), self::kg($s['total_caat_kg']),
                self::kg($s['payable_kg']), Weight::kg((string) $s['payable_kg'])->in('maund', 2), self::rs((int) $s['price_paise_per_maund']),
                self::rs((int) ($s['rate_cut_paise_per_maund'] ?? 0)), self::rs($value), self::rs($cost['charges_paise']), self::rs($landed),
                bccomp($maund, '0', 4) > 0 ? self::rs((int) round($landed / (float) $maund)) : '',
                $bill !== null ? trim(($bill->number ?? 'draft').' ('.$bill->status.')') : 'not billed yet'];
            $sum['net'] = bcadd($sum['net'], (string) $s['net_kg'], 3);
            $sum['caat'] = bcadd($sum['caat'], (string) $s['total_caat_kg'], 3);
            $sum['payable'] = bcadd($sum['payable'], (string) $s['payable_kg'], 3);
            $sum['value'] += $value;
            $sum['charges'] += $cost['charges_paise'];
            $sum['landed'] += $landed;
        }
        if ($rows !== []) {
            $rows[] = ['TOTAL', count($rows).' vehicles', '', '', self::kg($sum['net']), '', '', self::kg($sum['caat']), self::kg($sum['payable']),
                Weight::kg($sum['payable'])->in('maund', 2), '', '', self::rs($sum['value']), self::rs($sum['charges']), self::rs($sum['landed']), '', ''];
        }

        return self::part(self::REPORTS['settlements'], $span,
            ['Date', 'Entry', 'Vehicle', 'Supplier', 'Net kg', 'Waste Caat kg', 'Per-maund Caat kg', 'Total Caat kg', 'Payable kg', 'Payable maund',
                'Price / md', 'Rate cut / md', 'Purchase value', 'Charges (stock cost)', 'Landed cost', 'Landed / payable md', 'Bill'],
            $rows, [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            ['Payable = net − Waste Caat − Caat per Maund × net maunds. Purchase value = payable maunds × settled price, less the rate cut. '
                .'Landed cost = purchase value + the vehicle\'s charges marked for the stock cost (freight, unloading…). Settled vehicles only.']);
    }

    /**
     * @param  Collection<int, GateEntry>  $entries
     * @return array<string, mixed>
     */
    private function labResults(Collection $entries, string $span): array
    {
        $rows = [];
        foreach ($entries as $e) {
            $test = $e->labTests()->latestFirst()->first();
            if ($test === null) {
                continue;
            }
            $decision = GateEvent::query()->where('gate_entry_id', $e->id)->whereIn('event', ['lab_accepted', 'lab_rejected'])
                ->newestFirst()->first();
            $outcome = match (true) {
                $decision === null => 'waiting',
                $decision->event === 'lab_rejected' => (($decision->data['automatic'] ?? false) ? 'rejected (automatic)' : 'rejected'),
                (bool) ($decision->data['after_negotiation'] ?? false) => 'accepted after negotiation',
                default => 'accepted',
            };
            foreach ((array) ($test->evaluation ?? []) as $r) {
                $rows[] = [$this->date($e), (string) $e->number, PakistanIds::formatVehicleNumber($e->registration_no), (string) ($e->party->name ?? ''),
                    $r['name'].' ('.$r['name_ur'].')', self::pct((int) $r['value_bp']), self::pct((int) $r['standard_bp']), self::pct((int) $r['grace_bp']),
                    match ($r['verdict']) {
                        LabStandards::OVER => 'ABOVE GRACE', LabStandards::GRACE => 'within grace', default => 'within standard',
                    }, $outcome];
            }
        }

        return self::part(self::REPORTS['lab_results'], $span,
            ['Date', 'Entry', 'Vehicle', 'Supplier', 'Test', 'Result', 'Standard up to', 'Grace up to', 'Reading', 'Lab decision'], $rows, [5, 6, 7],
            ['Each result against the limits in force when it was recorded. Above grace: the lot is rejected automatically.']);
    }

    /**
     * @param  Collection<int, GateEntry>  $entries
     * @return array<string, mixed>
     */
    private function rejections(Collection $entries, string $span): array
    {
        $rows = [];
        foreach ($entries as $e) {
            $rejected = GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'lab_rejected')->chronological()->get();
            if ($rejected->isEmpty()) {
                continue;
            }
            $last = $rejected->last();
            // Accepted after the last rejection: the latest lab decision is an acceptance after negotiation.
            $latest = GateEvent::query()->where('gate_entry_id', $e->id)->whereIn('event', ['lab_accepted', 'lab_rejected'])->newestFirst()->first();
            $after = $latest?->event === 'lab_accepted' && ($latest->data['after_negotiation'] ?? false) ? $latest : null;
            $held = GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'quarantined')->newestFirst()->first();
            $failed = array_map(fn (array $r) => $r['name'].' '.self::pct((int) $r['value_bp']).' > '.self::pct((int) $r['grace_bp']), (array) ($last->data['over_grace'] ?? []));
            $rows[] = [$this->date($e), (string) $e->number, PakistanIds::formatVehicleNumber($e->registration_no), (string) ($e->party->name ?? ''),
                ($last->data['automatic'] ?? false) ? 'automatic' : 'by the supervisor',
                $failed !== [] ? implode('; ', $failed) : (string) ($last->data['note'] ?? ''),
                $held !== null ? (string) $held->data['location'] : '',
                match (true) {
                    $after !== null => 'accepted after negotiation at '.self::rs((int) ($after->data['price_paise_per_maund'] ?? 0)).' / md',
                    $e->status === 'exited' => 'left the mill',
                    $e->status === 'cancelled' => 'cancelled',
                    default => 'still inside',
                }];
        }

        return self::part(self::REPORTS['rejections'], $span,
            ['Date', 'Entry', 'Vehicle', 'Supplier', 'Rejected', 'What failed / reason', 'Held at (quarantine)', 'Outcome'], $rows, [],
            ['A rejected lot is not stock and is not billed unless it is accepted after negotiation, with an agreed price and the Purchase Manager\'s approval.']);
    }

    /**
     * @param  Collection<int, GateEntry>  $entries
     * @return array<string, mixed>
     */
    private function negotiations(Collection $entries, string $span): array
    {
        $rows = [];
        $names = fn (?string $id) => $id === null ? '' : (string) LoginAccount::query()->whereKey($id)->value('username');
        foreach ($entries as $e) {
            foreach (GatePriceVersion::query()->where('gate_entry_id', $e->id)->orderBy('version')->get() as $v) {
                $rows[] = [$this->date($e), (string) $e->number, PakistanIds::formatVehicleNumber($e->registration_no), (string) ($e->party->name ?? ''),
                    (string) $v->version, self::rs($v->price_paise_per_maund), $v->kind, $v->status, (string) ($v->reason ?? ''),
                    $names($v->proposed_by), $names($v->decided_by), (string) ($v->decision_note ?? '')];
            }
        }

        return self::part(self::REPORTS['negotiations'], $span,
            ['Date', 'Entry', 'Vehicle', 'Supplier', 'Version', 'Price / md', 'Kind', 'Status', 'Reason', 'Proposed by', 'Decided by', 'Decision note'], $rows, [4, 5],
            ['Versions are never changed after a decision; the last accepted one is the price used.']);
    }

    /**
     * @param  Collection<int, GateEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function caatAnalysis(Collection $entries, string $span): array
    {
        $rows = [];
        $bySupplier = [];
        foreach ($entries as $e) {
            $s = $this->settlement($e);
            if ($s === null) {
                continue;
            }
            $share = bccomp((string) $s['net_kg'], '0', 3) > 0 ? bcmul(bcdiv((string) $s['total_caat_kg'], (string) $s['net_kg'], 6), '100', 2) : '0';
            $rows[] = [$this->date($e), (string) $e->number, PakistanIds::formatVehicleNumber($e->registration_no), (string) ($e->party->name ?? ''),
                self::kg($s['net_kg']), self::kg($s['waste_caat_kg']), (string) $s['per_maund_rate_kg'], self::kg($s['per_maund_caat_kg']),
                self::kg($s['total_caat_kg']), $share.' %'];
            $name = (string) ($e->party->name ?? '—');
            $bySupplier[$name] ??= ['vehicles' => 0, 'net' => '0', 'waste' => '0', 'per_maund' => '0', 'total' => '0'];
            $bySupplier[$name]['vehicles']++;
            foreach (['net' => 'net_kg', 'waste' => 'waste_caat_kg', 'per_maund' => 'per_maund_caat_kg', 'total' => 'total_caat_kg'] as $k => $field) {
                $bySupplier[$name][$k] = bcadd($bySupplier[$name][$k], (string) $s[$field], 3);
            }
        }
        $supplierRows = [];
        foreach ($bySupplier as $name => $t) {
            $supplierRows[] = [$name, (string) $t['vehicles'], self::kg($t['net']), self::kg($t['waste']), self::kg($t['per_maund']), self::kg($t['total']),
                bccomp($t['net'], '0', 3) > 0 ? bcmul(bcdiv($t['total'], $t['net'], 6), '100', 2).' %' : ''];
        }

        return [
            self::part(self::REPORTS['caat_analysis'].' — by vehicle', $span,
                ['Date', 'Entry', 'Vehicle', 'Supplier', 'Net kg', 'Waste Caat kg', 'Per-maund rate (kg/md)', 'Per-maund Caat kg', 'Total Caat kg', 'Caat % of net'],
                $rows, [4, 5, 6, 7, 8, 9]),
            self::part(self::REPORTS['caat_analysis'].' — by supplier', $span,
                ['Supplier', 'Vehicles', 'Net kg', 'Waste Caat kg', 'Per-maund Caat kg', 'Total Caat kg', 'Caat % of net'], $supplierRows, [1, 2, 3, 4, 5, 6]),
        ];
    }

    /**
     * @param  Collection<int, GateEntry>  $entries
     * @return array<string, mixed>
     */
    private function priceVariance(Collection $entries, string $span): array
    {
        $rows = [];
        $total = 0;
        foreach ($entries as $e) {
            $s = $this->settlement($e);
            $offered = $e->sauda?->rate_paise_per_maund;
            if ($s === null || $offered === null) {
                continue;
            }
            $accepted = (int) $s['price_paise_per_maund'];
            $maund = bcdiv((string) $s['payable_kg'], '40', 4);
            $amount = (int) round((float) bcmul($maund, (string) ((int) $offered - $accepted), 2));
            $total += $amount;
            $rows[] = [$this->date($e), (string) $e->number, PakistanIds::formatVehicleNumber($e->registration_no), (string) ($e->party->name ?? ''),
                self::rs((int) $offered), self::rs($accepted), self::rs((int) $offered - $accepted), Weight::kg((string) $s['payable_kg'])->in('maund', 2), self::rs($amount)];
        }
        if ($rows !== []) {
            $rows[] = ['TOTAL', count($rows).' vehicles', '', '', '', '', '', '', self::rs($total)];
        }

        return self::part(self::REPORTS['price_variance'], $span,
            ['Date', 'Entry', 'Vehicle', 'Supplier', 'Offered (sauda) / md', 'Accepted / md', 'Difference / md', 'Payable maund', 'Difference (Rs)'],
            $rows, [4, 5, 6, 7, 8],
            ['Difference = (offered − last accepted) × payable maunds: the negotiated reduction. An analysis only: it is not booked as income.']);
    }

    private function date(GateEntry $e): string
    {
        return $e->arrived_at->timezone('Asia/Karachi')->format('d M Y');
    }

    private static function kg(mixed $kg): string
    {
        return $kg === null || $kg === '' ? '' : number_format((float) $kg, 2);
    }

    private static function rs(int $paise): string
    {
        return Money::paise($paise)->grouped();
    }

    private static function pct(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').' %';
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
}

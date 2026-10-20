<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Accounts\PurchaseBillCalculator;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Accounts\StockValuation;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\Accounts\PurchaseBill;
use App\Models\Entity;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Models\Gate\LabTest;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;
use App\Support\Export\XlsxWorkbook;
use App\Support\Values\Money;
use App\Support\Values\PakistanIds;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The vehicle register: every vehicle that came in (paddy received) or went out (dispatch) on a
 * day, with its weights, deductions and the price it is paid / billed at, worked out step by step.
 *
 * Weight (all from the weighbridge indicator, the latest weighment of each kind that was not replaced):
 *   net        = gross (loaded vehicle) − tare (empty vehicle)
 *   deduction  = from the lab decision, with the deduction rules frozen in it:
 *                per bag (grams × bags), excess moisture / foreign matter (net × % over the free limit × factor)
 *   payable    = net − deduction                          (inbound; outbound bills the net)
 *
 * Price (1 maund = 40 kg):
 *   inbound    gross amount = payable kg × sauda rate per maund ÷ 40
 *              rate cut     = payable kg × quality cut per maund ÷ 40  (from the lab decision)
 *              net payable  = gross − rate cut, rounded to the rupee; income tax u/s 153 is withheld when it applies
 *   outbound   value        = net kg × sauda rate per maund ÷ 40, plus sales tax / further tax on the invoice
 * Once the bill exists its own figures are shown (they are what the books hold); before that it is an estimate.
 */
final class VehicleRegister
{
    public const SIDES = ['inbound' => 'Vehicles in (paddy received)', 'outbound' => 'Vehicles out (dispatch)'];

    public const STATUS_LABELS = [
        'arrived' => 'Arrived', 'gross_weighed' => 'Loaded weight taken', 'lab_accepted' => 'Lab accepted', 'lab_rejected' => 'Lab rejected',
        'unloaded' => 'Unloaded', 'tare_weighed' => 'Empty weight taken', 'loaded' => 'Loaded', 'slip_issued' => 'Slip issued',
        'gate_pass_issued' => 'Gate pass issued', 'exited' => 'Left the mill', 'cancelled' => 'Cancelled',
        'settlement_approved' => 'Settlement approved',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PdfRenderer $pdf,
        private readonly PurchaseBillCalculator $calculator,
        private readonly DeductionCalculator $deductions,
    ) {}

    /**
     * Vehicles that arrived or left on the day (Pakistan time), oldest first.
     *
     * @return Builder<GateEntry>
     */
    public function query(CarbonImmutable $day, ?string $direction = null): Builder
    {
        [$start, $end] = self::bounds($day);

        return GateEntry::query()
            ->with(['party', 'product', 'sauda', 'driver', 'vehicle', 'godown'])
            ->whereNull('archived_at')
            ->when($direction !== null, fn (Builder $q) => $q->where('direction', $direction))
            ->where(fn (Builder $q) => $q->whereBetween('arrived_at', [$start, $end])->orWhereBetween('exited_at', [$start, $end]));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the day as UTC instants (timestamps are stored in UTC) */
    public static function bounds(CarbonImmutable $day): array
    {
        $local = CarbonImmutable::parse($day->toDateString(), 'Asia/Karachi');

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc()];
    }

    /**
     * What the vehicle is paid / billed at, and how it was worked out.
     *
     * @return array{state: string, label: string, rate_paise: int|null, kg: string|null, gross_paise: int|null, cut_paise: int, tax_paise: int,
     *     wht_paise: int, round_off_paise: int, net_paise: int|null, bill: string|null, steps: list<string>}
     */
    public function price(GateEntry $e): array
    {
        $none = fn (string $why) => ['state' => 'none', 'label' => 'Not priced yet', 'rate_paise' => null, 'kg' => null, 'gross_paise' => null, 'cut_paise' => 0,
            'tax_paise' => 0, 'wht_paise' => 0, 'round_off_paise' => 0, 'net_paise' => null, 'bill' => null, 'steps' => [$why]];

        return $e->direction === 'outbound' ? $this->salePrice($e, $none) : $this->purchasePrice($e, $none);
    }

    /**
     * @param  callable(string): array<string, mixed>  $none
     * @return array<string, mixed>
     */
    private function purchasePrice(GateEntry $e, callable $none): array
    {
        $sauda = $e->sauda_id !== null ? ($e->relationLoaded('sauda') ? $e->sauda : Sauda::query()->find($e->sauda_id)) : null;
        if ($sauda === null) {
            return $none('No sauda: the price is not set (a purchase is paid at its sauda rate).');
        }
        $rate = (int) $sauda->rate_paise_per_maund;
        $bill = PurchaseBill::query()->where('gate_entry_id', $e->id)->where('status', '<>', PurchaseBill::CANCELLED)->first();
        if ($bill !== null) {
            $steps = [
                'Payable '.self::kg($bill->payable_kg).' = '.self::maund($bill->payable_kg).' maund (÷ 40)',
                'Gross amount = '.self::maund($bill->payable_kg).' maund × '.self::rs($bill->rate_paise_per_maund).' = '.self::rs($bill->gross_paise),
            ];
            if ($bill->rate_cut_paise > 0) {
                $steps[] = 'Less quality rate cut = '.self::rs($bill->rate_cut_paise);
            }
            if ($bill->round_off_paise !== 0) {
                $steps[] = 'Round off to the rupee = '.self::rs($bill->round_off_paise);
            }
            if ($bill->wht_paise > 0) {
                $steps[] = 'Less income tax withheld u/s 153 ('.number_format($bill->wht_rate_bp / 100, 2).' %) = '.self::rs($bill->wht_paise);
            }
            $steps[] = 'Net payable to the supplier = '.self::rs($bill->net_paise).' ('.self::billName('purchase bill', $bill->number, $bill->status).')';

            return ['state' => $bill->status === PurchaseBill::POSTED ? 'final' : 'bill_draft', 'label' => ucfirst(self::billName('purchase bill', $bill->number, $bill->status)),
                'rate_paise' => $bill->rate_paise_per_maund, 'kg' => $bill->payable_kg, 'gross_paise' => $bill->gross_paise, 'cut_paise' => $bill->rate_cut_paise,
                'tax_paise' => 0, 'wht_paise' => $bill->wht_paise, 'round_off_paise' => $bill->round_off_paise, 'net_paise' => $bill->net_paise,
                'bill' => $bill->number, 'steps' => $steps];
        }
        if ($e->payable_kg === null) {
            return $none('Not priced yet: both weights are needed (rate '.self::rs($rate).' per maund).') + ['rate_paise' => $rate];
        }
        $cutPerMaund = (int) ($e->rate_cut_paise_per_maund ?? 0);
        $a = $this->calculator->calculate((string) $e->payable_kg, $rate, $cutPerMaund, 0, (bool) config('ricemill.accounts.round_bills_to_rupee'));
        $steps = [
            'Payable '.self::kg($e->payable_kg).' = '.self::maund($e->payable_kg).' maund (÷ 40)',
            'Gross amount = '.self::maund($e->payable_kg).' maund × '.self::rs($rate).' (sauda '.$sauda->number.') = '.self::rs($a['gross_paise']),
        ];
        if ($a['rate_cut_paise'] > 0) {
            $steps[] = 'Less quality rate cut '.self::rs($cutPerMaund).' per maund = '.self::rs($a['rate_cut_paise']);
        }
        if ($a['round_off_paise'] !== 0) {
            $steps[] = 'Round off to the rupee = '.self::rs($a['round_off_paise']);
        }
        $steps[] = 'Estimated payable = '.self::rs($a['net_paise']).' (the purchase bill is made when the slip is issued; income tax u/s 153 may be withheld from it)';

        return ['state' => 'estimate', 'label' => 'Estimate', 'rate_paise' => $rate, 'kg' => (string) $e->payable_kg, 'gross_paise' => $a['gross_paise'],
            'cut_paise' => $a['rate_cut_paise'], 'tax_paise' => 0, 'wht_paise' => 0, 'round_off_paise' => $a['round_off_paise'], 'net_paise' => $a['net_paise'],
            'bill' => null, 'steps' => $steps];
    }

    /**
     * @param  callable(string): array<string, mixed>  $none
     * @return array<string, mixed>
     */
    private function salePrice(GateEntry $e, callable $none): array
    {
        $bill = SalesBill::query()->where('gate_entry_id', $e->id)->where('status', '<>', SalesBill::CANCELLED)->first();
        if ($bill !== null) {
            $tax = $bill->sales_tax_paise + $bill->further_tax_paise;
            $steps = [
                'Net '.self::kg($bill->quantity_kg).' = '.self::maund($bill->quantity_kg).' maund (÷ 40)',
                'Value = '.self::maund($bill->quantity_kg).' maund × '.self::rs($bill->rate_paise_per_maund).' = '.self::rs($bill->taxable_paise),
            ];
            if ($bill->sales_tax_paise > 0) {
                $steps[] = 'Plus sales tax = '.self::rs($bill->sales_tax_paise);
            }
            if ($bill->further_tax_paise > 0) {
                $steps[] = 'Plus further tax (buyer without STRN) = '.self::rs($bill->further_tax_paise);
            }
            if ($bill->round_off_paise !== 0) {
                $steps[] = 'Round off to the rupee = '.self::rs($bill->round_off_paise);
            }
            $steps[] = 'Invoice total receivable = '.self::rs($bill->total_paise).' ('.self::billName('sales invoice', $bill->number, $bill->status).')';

            return ['state' => $bill->status === SalesBill::POSTED ? 'final' : 'bill_draft', 'label' => ucfirst(self::billName('sales invoice', $bill->number, $bill->status)),
                'rate_paise' => $bill->rate_paise_per_maund, 'kg' => (string) $bill->quantity_kg, 'gross_paise' => $bill->taxable_paise, 'cut_paise' => 0,
                'tax_paise' => $tax, 'wht_paise' => 0, 'round_off_paise' => $bill->round_off_paise, 'net_paise' => $bill->total_paise,
                'bill' => $bill->number, 'steps' => $steps];
        }
        $order = $e->dispatch_order_id !== null ? DispatchOrder::query()->find($e->dispatch_order_id) : null;
        $sauda = $order !== null ? Sauda::query()->find($order->sauda_id) : ($e->sauda_id !== null ? Sauda::query()->find($e->sauda_id) : null);
        if ($sauda === null) {
            return $none('No dispatch order / sauda: the price is not set.');
        }
        $rate = (int) $sauda->rate_paise_per_maund;
        if ($e->net_kg === null || bccomp((string) $e->net_kg, '0', 3) <= 0) {
            return $none('Not priced yet: both weights are needed (rate '.self::rs($rate).' per maund).') + ['rate_paise' => $rate];
        }
        $value = PurchaseBillCalculator::perMaund((string) $e->net_kg, $rate);

        return ['state' => 'estimate', 'label' => 'Estimate', 'rate_paise' => $rate, 'kg' => (string) $e->net_kg, 'gross_paise' => $value, 'cut_paise' => 0,
            'tax_paise' => 0, 'wht_paise' => 0, 'round_off_paise' => 0, 'net_paise' => $value, 'bill' => null, 'steps' => [
                'Net '.self::kg($e->net_kg).' = '.self::maund($e->net_kg).' maund (÷ 40)',
                'Value = '.self::maund($e->net_kg).' maund × '.self::rs($rate).' (sauda '.$sauda->number.') = '.self::rs($value),
                'Estimate before tax: the sales invoice adds sales tax / further tax when the slip is issued.',
            ]];
    }

    /**
     * How the weight was worked out: the weighments, then net, deductions and payable.
     *
     * @return list<string>
     */
    public function weightSteps(GateEntry $e): array
    {
        $steps = [];
        $gross = $e->gross_kg !== null ? self::kg($e->gross_kg) : null;
        $tare = $e->tare_kg !== null ? self::kg($e->tare_kg) : null;
        $steps[] = $e->direction === 'outbound'
            ? 'Empty vehicle (tare) on arrival: '.($tare ?? 'not weighed yet').' · loaded vehicle (gross) before leaving: '.($gross ?? 'not weighed yet')
            : 'Loaded vehicle (gross) on arrival: '.($gross ?? 'not weighed yet').' · empty vehicle (tare) after unloading: '.($tare ?? 'not weighed yet');
        if ($e->net_kg === null) {
            return $steps;
        }
        $steps[] = 'Net = '.$gross.' − '.$tare.' = '.self::kg($e->net_kg).' ('.self::maund($e->net_kg).' maund)';
        if ($e->direction === 'outbound') {
            return $steps;
        }

        foreach ($this->deductionLines($e) as $line) {
            $steps[] = $line;
        }
        $steps[] = 'Payable = net − deduction = '.self::kg($e->net_kg).' − '.self::kg($e->deduction_kg ?? '0').' = '.self::kg($e->payable_kg ?? $e->net_kg)
            .' ('.self::maund($e->payable_kg ?? $e->net_kg).' maund)';

        return $steps;
    }

    /** @return list<string> one line per deduction rule that applied, from the lab decision */
    private function deductionLines(GateEntry $e): array
    {
        $decision = GateEvent::query()->where('gate_entry_id', $e->id)->whereIn('event', ['lab_accepted', 'lab_rejected'])
            ->newestFirst()->first();
        if ($decision === null) {
            return ['No lab decision yet: no deduction.'];
        }
        if ($decision->event === 'lab_rejected') {
            return ['Lab rejected the lot: nothing is bought.'];
        }
        $rules = array_map(fn (array $r) => (new DeductionRule)->forceFill($r), (array) ($decision->data['rules'] ?? []));
        $result = $this->deductions->calculate((string) $e->net_kg, $e->bag_count, (array) ($decision->data['measurements'] ?? []), $rules);
        $lines = [];
        foreach ($result['lines'] as $l) {
            $lines[] = match ($l['method'] ?? null) {
                'per_bag' => 'Bag weight: '.$l['bags'].' bags × '.$l['grams_per_bag'].' g = '.self::kg($l['deduction_kg']),
                'excess_weight' => ucfirst(str_replace('_', ' ', (string) $l['parameter'])).' '.self::pct($l['value_bp']).' (free up to '.self::pct($l['free_bp'])
                    .'): '.self::pct($l['value_bp'] - $l['free_bp']).' over → '.self::kg($l['deduction_kg']),
                'rate_cut' => ucfirst(str_replace('_', ' ', (string) $l['parameter'])).' '.self::pct($l['value_bp']).' (free up to '.self::pct($l['free_bp'])
                    .'): rate cut '.self::rs($l['rate_cut_paise_per_maund']).' per maund',
                default => ucfirst(str_replace('_', ' ', (string) $l['parameter'])).': above the reject limit',
            };
        }

        return $lines === [] ? ['Lab accepted with no deduction.'] : array_merge($lines, ['Total deduction = '.self::kg($result['deduction_kg'])]);
    }

    /**
     * One row of the register.
     *
     * @return array<string, mixed>
     */
    public function row(GateEntry $e): array
    {
        $price = $this->price($e);

        return [
            'entry' => $e,
            'number' => $e->number,
            'direction' => $e->direction,
            'vehicle' => $e->no_plate ? 'No plate: '.$e->vehicle_description : $e->registration_no,
            'vehicle_type' => Vehicle::typeLabel($e->vehicle_type ?? $e->vehicle?->vehicle_type),
            'driver' => $e->driver->name ?? $e->driver_name,
            'driver_phone' => $e->driver?->phone,
            'party' => $e->party?->name,
            'product' => $e->product?->name,
            'sauda' => $e->sauda?->number,
            'bags' => $e->bag_count,
            'godown' => $e->godown?->name,
            'arrived' => $e->arrived_at->timezone('Asia/Karachi'),
            'exited' => $e->exited_at?->timezone('Asia/Karachi'),
            'gross_kg' => $e->gross_kg, 'tare_kg' => $e->tare_kg, 'net_kg' => $e->net_kg,
            'deduction_kg' => $e->deduction_kg, 'payable_kg' => $e->direction === 'outbound' ? $e->net_kg : $e->payable_kg,
            'status' => self::STATUS_LABELS[$e->status] ?? $e->status,
            'flags' => $e->flags ?? [],
            'price' => $price,
        ];
    }

    /**
     * The day: rows of both sides and the totals.
     *
     * @return array{day: CarbonImmutable, sides: array<string, array{title: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}>}
     */
    public function day(CarbonImmutable $day, ?string $direction = null): array
    {
        $sides = [];
        foreach (array_keys(self::SIDES) as $side) {
            if ($direction !== null && $direction !== $side) {
                continue;
            }
            /** @var Collection<int, GateEntry> $entries */
            $entries = $this->query($day, $side)->orderBy('arrived_at')->get();
            $rows = $entries->map(fn (GateEntry $e) => $this->row($e))->values()->all();
            $sides[$side] = ['title' => self::SIDES[$side], 'rows' => $rows, 'totals' => self::totals($rows)];
        }

        return ['day' => $day, 'sides' => $sides];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function totals(array $rows): array
    {
        $sum = fn (string $key) => array_reduce($rows, fn (string $c, array $r) => $r[$key] !== null ? bcadd($c, (string) $r[$key], 3) : $c, '0');

        return [
            'vehicles' => count($rows),
            'inside' => count(array_filter($rows, fn (array $r) => $r['exited'] === null && $r['entry']->status !== 'cancelled')),
            'bags' => array_sum(array_map(fn (array $r) => (int) $r['bags'], $rows)),
            'net_kg' => $sum('net_kg'), 'deduction_kg' => $sum('deduction_kg'), 'payable_kg' => $sum('payable_kg'),
            'amount_paise' => array_sum(array_map(fn (array $r) => (int) ($r['price']['net_paise'] ?? 0), $rows)),
        ];
    }

    public const EVENT_LABELS = [
        'arrived' => 'Arrived at the gate', 'gross_weighed' => 'Loaded weight (gross) taken', 'tare_weighed' => 'Empty weight (tare) taken',
        'lab_accepted' => 'Lab accepted the lot', 'lab_rejected' => 'Lab rejected the lot', 'unloaded' => 'Unloaded', 'loaded' => 'Loaded',
        'slip_issued' => 'Weighment slip issued', 'gate_pass_issued' => 'Gate pass issued', 'exited' => 'Left the mill', 'cancelled' => 'Cancelled',
        'settlement_proposed' => 'Caat and price settlement proposed', 'settlement_approved' => 'Settlement approved',
        'settlement_reopened' => 'Settlement reopened', 'quarantined' => 'Rejected lot held apart (quarantine)',
    ];

    /**
     * Everything known about one vehicle's visit, for the details panel and the printed slip.
     *
     * @return array{row: array<string, mixed>, sections: list<array{title: string, fields: list<array{0: string, 1: string}>}>,
     *     weighments: list<array<string, string>>, lab: list<array{0: string, 1: string}>, timeline: list<array{0: string, 1: string, 2: string}>,
     *     weightSteps: list<string>, priceSteps: list<string>, flags: list<string>}
     */
    public function details(GateEntry $e): array
    {
        $e->loadMissing(['party', 'product', 'sauda', 'driver', 'vehicle.owner', 'godown']);
        $row = $this->row($e);
        $inbound = $e->direction === 'inbound';
        $vehicle = $e->vehicle;
        $driver = $e->driver;
        $party = $e->party;
        $order = $e->dispatch_order_id !== null ? DispatchOrder::query()->find($e->dispatch_order_id) : null;
        $sauda = $e->sauda;
        $end = $e->exited_at ?? now();
        $minutes = (int) $e->arrived_at->diffInMinutes($end, true);
        $price = $row['price'];

        $sections = [
            ['title' => 'Vehicle and driver', 'fields' => array_values(array_filter([
                ['Vehicle number', $e->no_plate ? 'No number plate (ref. '.$e->registration_no.')' : PakistanIds::formatVehicleNumber($e->registration_no)],
                $e->no_plate ? ['Description', (string) $e->vehicle_description] : null,
                ['Vehicle type', Vehicle::typeLabel($e->vehicle_type ?? $vehicle?->vehicle_type)],
                ['Vehicle owner', $vehicle?->owner->name ?? '—'],
                ['Capacity', $vehicle?->capacity_kg !== null ? self::kg($vehicle->capacity_kg) : '—'],
                $vehicle?->is_blocked ? ['BLOCKED', (string) ($vehicle->blocked_reason ?? 'yes')] : null,
                ['Driver', $driver->name ?? $e->driver_name ?? '—'],
                ['Driver phone', $driver->phone ?? '—'],
                ['Licence', $driver?->licence_no !== null ? $driver->licence_no.($driver->licence_valid_until ? ' (valid until '.$driver->licence_valid_until->format('d M Y').')' : '') : '—'],
            ]))],
            ['title' => 'Trip', 'fields' => array_values(array_filter([
                ['Entry number', (string) $e->number],
                ['Direction', $inbound ? 'In — '.str_replace('_', ' ', $e->purpose) : 'Out — '.str_replace('_', ' ', $e->purpose)],
                [$inbound ? 'Supplier' : 'Buyer', $party !== null ? $party->name.($party->phone ? ' · '.$party->phone : '') : '—'],
                ['Product', $e->product->name ?? '—'],
                ['Sauda', $sauda !== null ? $sauda->number.' · '.self::rs($sauda->rate_paise_per_maund).' per maund' : '—'],
                $order !== null ? ['Dispatch order', $order->number] : null,
                ['Bags', $e->bag_count !== null ? (string) $e->bag_count : '—'],
                [$inbound ? 'Unloaded into' : 'Loaded from', $e->godown->name ?? '—'],
                ['Arrived', $e->arrived_at->timezone('Asia/Karachi')->format('d M Y H:i')],
                ['Left', $e->exited_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? 'Still inside'],
                ['Time inside', intdiv($minutes, 60).' h '.($minutes % 60).' min'.($e->exited_at === null ? ' so far' : '')],
                ['Status', $row['status']],
            ]))],
            ['title' => 'Weight and money', 'fields' => array_values(array_filter([
                ['Gross', self::kg($e->gross_kg)],
                ['Tare', self::kg($e->tare_kg)],
                ['Net', self::kg($e->net_kg).($e->net_kg !== null ? ' = '.self::maund($e->net_kg).' maund' : '')],
                $inbound ? ['Deduction', self::kg($e->deduction_kg)] : null,
                $inbound ? ['Rate cut', $e->rate_cut_paise_per_maund ? self::rs($e->rate_cut_paise_per_maund).' per maund' : '—'] : null,
                [$inbound ? 'Payable weight' : 'Billed weight', self::kg($row['payable_kg']).($row['payable_kg'] !== null ? ' = '.self::maund($row['payable_kg']).' maund' : '')],
                ['Rate per maund', self::rs($price['rate_paise'])],
                [$inbound ? 'Payable to supplier' : 'Receivable from buyer', self::rs($price['net_paise']).($price['state'] === 'estimate' ? ' (estimate)' : '')],
                ['Bill', $price['state'] === 'none' || $price['state'] === 'estimate' ? 'Not made yet' : $price['label']],
            ]))],
        ];

        $weighments = Weighment::query()->with(['scale', 'creator'])->where('gate_entry_id', $e->id)->orderBy('captured_at')->get()
            ->map(fn (Weighment $w) => [
                'kind' => $w->kind === 'gross' ? 'Gross (loaded)' : ($w->kind === 'tare' ? 'Tare (empty)' : ucfirst($w->kind)),
                'when' => $w->captured_at->timezone('Asia/Karachi')->format('d M H:i:s'),
                'weight' => self::kg($w->weight_kg),
                'scale' => $w->scale->name ?? '—',
                'source' => $w->source === 'manual_override' ? 'MANUAL (approved)' : 'Indicator',
                'by' => $w->creator->username ?? '—',
                'note' => trim(($w->supersedes_id ? 'Re-weigh. ' : '').($w->reason ?? '').' '.implode(', ', array_map(fn ($f) => GateEntryResource::FLAG_LABELS[$f] ?? $f, $w->flags ?? []))),
            ])->values()->all();

        $lab = [];
        $test = LabTest::query()->where('gate_entry_id', $e->id)->orderByDesc('tested_at')->first();
        if ($test !== null) {
            foreach (LabTest::PARAMETERS as $parameter => $column) {
                $value = $test->getAttribute($column);
                if ($value !== null) {
                    $lab[] = [ucfirst(str_replace('_', ' ', $parameter)), self::pct((int) $value)];
                }
            }
            $lab[] = ['Grade', $test->grade ?? '—'];
            $lab[] = ['Tested', $test->tested_at->timezone('Asia/Karachi')->format('d M H:i').($test->sample_code ? ' · sample '.$test->sample_code : '')];
            if ($test->remarks) {
                $lab[] = ['Remarks', $test->remarks];
            }
        }

        $timeline = GateEvent::query()->with('actor')->where('gate_entry_id', $e->id)->chronological()->get()
            ->map(fn (GateEvent $ev) => [
                $ev->occurred_at->timezone('Asia/Karachi')->format('d M H:i'),
                (self::EVENT_LABELS[$ev->event] ?? ucfirst(str_replace('_', ' ', $ev->event)))
                    .(isset($ev->data['note']) && is_string($ev->data['note']) ? ' — '.$ev->data['note'] : '')
                    .(isset($ev->data['reason']) && is_string($ev->data['reason']) ? ' — '.$ev->data['reason'] : ''),
                $ev->actor->username ?? '—',
            ])->values()->all();

        return [
            'row' => $row, 'sections' => $sections, 'weighments' => $weighments, 'lab' => $lab, 'timeline' => $timeline,
            'weightSteps' => $this->weightSteps($e), 'priceSteps' => $price['steps'],
            'flags' => array_map(fn ($f) => GateEntryResource::FLAG_LABELS[$f] ?? $f, $e->flags ?? []),
        ] + $this->settlementSections($e, $test);
    }

    /**
     * The final settlement layout (inbound): lab results against the limits, every price version, the
     * Caats, the money (bill, tax withheld, paid, outstanding), what stock received and the landed cost,
     * and who prepared, checked and approved it.
     *
     * @return array{labEval: list<array{0: string, 1: string, 2: string, 3: string, 4: string}>, prices: list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>,
     *     settlement: list<array{0: string, 1: string}>, finance: list<array{0: string, 1: string}>, approvals: list<array{0: string, 1: string, 2: string}>}
     */
    private function settlementSections(GateEntry $e, ?LabTest $test): array
    {
        $empty = ['labEval' => [], 'prices' => [], 'settlement' => [], 'finance' => [], 'approvals' => []];
        if ($e->direction !== 'inbound') {
            return $empty;
        }
        $pct = fn (int $bp) => self::pct($bp);
        $who = fn (?string $id) => $id === null ? '—' : (string) (LoginAccount::query()->whereKey($id)->value('username') ?? '—');
        $out = $empty;
        foreach ((array) ($test->evaluation ?? []) as $r) {
            $out['labEval'][] = [$r['name'].' ('.$r['name_ur'].')', $pct((int) $r['value_bp']), $pct((int) $r['standard_bp']), $pct((int) $r['grace_bp']),
                match ($r['verdict']) {
                    LabStandards::OVER => 'ABOVE GRACE', LabStandards::GRACE => 'within grace', default => 'within standard',
                }];
        }
        foreach (GatePriceVersion::query()->where('gate_entry_id', $e->id)->orderBy('version')->get() as $v) {
            $out['prices'][] = [(string) $v->version, self::rs($v->price_paise_per_maund), $v->status, (string) ($v->reason ?? '—'),
                $who($v->proposed_by), $who($v->decided_by)];
        }
        $approved = app(GateService::class)->settlementApproved($e)
            ? GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'settlement_approved')->newestFirst()->first() : null;
        $proposal = GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'settlement_proposed')->newestFirst()->first();
        if ($approved !== null) {
            $s = (array) $approved->data;
            $out['settlement'] = [
                ['Net weight', self::kg($s['net_kg'])], ['Waste Caat', self::kg($s['waste_caat_kg'])],
                ['Caat per Maund', self::kg($s['per_maund_caat_kg']).' ('.$s['per_maund_rate_kg'].' kg × maunds)'], ['Total Caat', self::kg($s['total_caat_kg'])],
                ['Payable weight', self::kg($s['payable_kg']).' = '.self::maund((string) $s['payable_kg']).' maund'],
                ['Last accepted price', self::rs((int) $s['price_paise_per_maund']).' per maund (version '.($s['price_version'] ?? '—').')'],
            ];
        }
        $bill = PurchaseBill::query()->where('gate_entry_id', $e->id)->where('status', '<>', PurchaseBill::CANCELLED)->first();
        $landed = app(StockValuation::class)->landedCostOf($e->id);
        if ($bill !== null) {
            $outstanding = app(PurchaseBillService::class)->outstanding($bill);
            $out['finance'] = [
                ['Purchase bill', trim(($bill->number ?? 'draft').' ('.$bill->status.')')], ['Gross purchase value', self::rs($bill->gross_paise)],
                ['Quality rate cut', self::rs($bill->rate_cut_paise)], ['Income tax withheld (s.153)', self::rs($bill->wht_paise)],
                ['Net supplier liability', self::rs($bill->net_paise)],
                ['Paid', self::rs($bill->status === PurchaseBill::POSTED ? $bill->net_paise - $outstanding : 0)], ['Outstanding', self::rs($outstanding)],
                ['Charges added to stock cost', self::rs($landed['charges_paise'])], ['Landed cost', self::rs($landed['landed_paise'])],
                ['Stock received', self::kg($e->payable_kg)],
            ];
        }
        foreach (array_filter([$proposal, $approved]) as $ev) {
            $out['approvals'][] = [$ev->event === 'settlement_approved' ? 'Settlement approved' : 'Settlement prepared',
                $who($ev->actor_login_account_id), $ev->occurred_at->timezone('Asia/Karachi')->format('d M Y H:i')];
        }

        return $out;
    }

    /** Every vehicle's slip of the day in one file, one vehicle per page (the "print all slips" button). */
    public function daySlipsPdf(CarbonImmutable $day, ?string $direction = null): string
    {
        $entries = $this->query($day, $direction)->orderBy('direction')->orderBy('arrived_at')->get();

        return $this->pdf->render('gate.vehicle-slips', [
            'title' => 'Vehicle slips '.$day->format('d M Y'),
            'slips' => $entries->map(fn (GateEntry $e) => $this->details($e))->all(),
        ], $this->entity($this->tenant->entityId()), 'A4');
    }

    /** The slip of one vehicle: all its details, weights, deductions and price with the working. */
    public function vehicleSlipPdf(GateEntry $e): string
    {
        return $this->pdf->render('gate.vehicle-slip', ['title' => 'Vehicle slip '.$e->number, 'd' => $this->details($e)], $this->entity($e->entity_id), 'A4');
    }

    /** The end-of-day register of all vehicles in and out, with totals and signatures. */
    public function dayPdf(CarbonImmutable $day, ?string $direction = null): string
    {
        return $this->pdf->render('gate.day-register', ['title' => 'Vehicle register '.$day->format('d M Y'), 'register' => $this->day($day, $direction)],
            $this->entity($this->tenant->entityId()), 'A4-L');
    }

    public function dayExcel(CarbonImmutable $day, ?string $direction = null): string
    {
        $register = $this->day($day, $direction);
        $book = new XlsxWorkbook;
        $header = ['Entry', 'Vehicle', 'Type', 'Driver', 'Party', 'Product', 'Sauda', 'Bags', 'Arrived', 'Left', 'Gross kg', 'Tare kg', 'Net kg',
            'Deduction kg', 'Payable kg', 'Payable maund', 'Rate / maund (Rs)', 'Rate cut (Rs)', 'Tax (Rs)', 'WHT (Rs)', 'Amount (Rs)', 'Price', 'Status', 'Flags'];
        foreach ($register['sides'] as $side) {
            $rows = array_map(fn (array $r) => [
                $r['number'], $r['vehicle'], $r['vehicle_type'], $r['driver'], $r['party'], $r['product'], $r['sauda'], $r['bags'],
                $r['arrived']->format('Y-m-d H:i'), $r['exited']?->format('Y-m-d H:i'),
                $r['gross_kg'], $r['tare_kg'], $r['net_kg'], $r['deduction_kg'], $r['payable_kg'],
                $r['payable_kg'] !== null ? Weight::kg($r['payable_kg'])->in('maund', 2) : null,
                self::rupees($r['price']['rate_paise']), self::rupees($r['price']['cut_paise'] ?: null), self::rupees($r['price']['tax_paise'] ?: null),
                self::rupees($r['price']['wht_paise'] ?: null), self::rupees($r['price']['net_paise']), $r['price']['label'], $r['status'], implode(', ', $r['flags']),
            ], $side['rows']);
            $t = $side['totals'];
            $rows[] = ['TOTAL', $t['vehicles'].' vehicles', null, null, null, null, null, $t['bags'], null, null, null, null, $t['net_kg'], $t['deduction_kg'],
                $t['payable_kg'], Weight::kg($t['payable_kg'])->in('maund', 2), null, null, null, null, self::rupees($t['amount_paise']), null, null, null];
            $book->addSheet($side['title'], $header, $rows, [16, 17, 18, 19, 20]);
        }

        return $book->toString();
    }

    private function entity(?string $id): ?Entity
    {
        return $id === null ? null : $this->tenant->runAsSystem(fn () => Entity::query()->find($id));
    }

    public static function kg(string|int|null $kg): string
    {
        if ($kg === null) {
            return '—';
        }
        $exact = rtrim(rtrim(Weight::kg($kg)->in('kg', 3), '0'), '.');
        $negative = str_starts_with($exact, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($exact, '-')), 2, null);

        return ($negative ? '-' : '').Money::lakhs($whole).($fraction !== null ? '.'.$fraction : '').' kg';
    }

    public static function maund(string|int|null $kg): string
    {
        return $kg === null ? '—' : Weight::kg($kg)->in('maund', 2);
    }

    public static function rs(?int $paise): string
    {
        return $paise === null ? '—' : Money::paise($paise)->format();
    }

    /** "purchase bill PB/0012, posted" or "draft purchase bill" (a draft has no number yet) */
    private static function billName(string $kind, ?string $number, string $status): string
    {
        return $number === null ? $status.' '.$kind : $kind.' '.$number.', '.$status;
    }

    private static function rupees(?int $paise): ?float
    {
        return $paise === null ? null : round($paise / 100, 2);
    }

    private static function pct(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').' %';
    }
}

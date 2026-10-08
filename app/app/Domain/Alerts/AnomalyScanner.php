<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Pricing\PricingService;
use App\Models\Accounts\Payment;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\Gate\GateDeviceEvent;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\Weighment;
use App\Models\Masters\Party;
use App\Models\Procurement\Sauda;
use App\Models\Production\ProductionBatch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;

/**
 * Explainable anomaly rules over the last day's records (one entity at a time). Each finding
 * becomes an alert stating the rule, the numbers and why it fired. Findings are de-duplicated by a
 * stable key, so re-running the scan never raises the same alert twice. No data leaves the server.
 */
final class AnomalyScanner
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly PricingService $pricing,
    ) {}

    /** @return array<string, int> alerts raised per rule */
    public function scan(CarbonInterface $since, ?CarbonInterface $until = null): array
    {
        // Stored timestamps are UTC and the query builder does not convert timezones.
        $since = CarbonImmutable::instance($since)->utc();
        $until = CarbonImmutable::instance($until ?? now())->utc();
        $raised = [];
        foreach ([
            'weight_outlier' => fn () => $this->weightOutliers($since, $until),
            'rate_outlier' => fn () => $this->rateOutliers($since, $until),
            'after_hours' => fn () => $this->afterHours($since, $until),
            'repeat_plate_mismatch' => fn () => $this->repeatPlateMismatch($until),
            'manual_overrides' => fn () => $this->manualOverrides($until),
            'approvals_same_party' => fn () => $this->approvalsSameParty($until),
            'yield_drift' => fn () => $this->yieldDrift(),
        ] as $rule => $run) {
            $raised[$rule] = 0;
            foreach ($run() as $finding) {
                if ($this->raise($rule, ...$finding)) {
                    $raised[$rule]++;
                }
            }
        }

        return $raised;
    }

    /**
     * Net weight far from the vehicle's usual load (robust z-score with median and MAD).
     *
     * @return iterable<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: string}>
     */
    private function weightOutliers(CarbonInterface $since, CarbonInterface $until): iterable
    {
        $threshold = (float) config('ricemill.anomalies.net_weight_z');
        $minTrips = (int) config('ricemill.anomalies.min_trips');
        $slips = GateEvent::query()->where('event', 'slip_issued')->whereBetween('occurred_at', [$since, $until])->get();
        foreach ($slips as $slip) {
            $entry = GateEntry::query()->find($slip->gate_entry_id);
            if ($entry === null || $entry->net_kg === null) {
                continue;
            }
            $history = GateEntry::query()->where('registration_no', $entry->registration_no)->where('direction', $entry->direction)
                ->whereKeyNot($entry->id)->whereNotNull('net_kg')->where('arrived_at', '<=', $entry->arrived_at)
                ->orderByDesc('arrived_at')->limit(20)->pluck('net_kg')->map(fn ($v) => (float) $v)->all();
            if (count($history) < $minTrips) {
                continue;
            }
            $median = self::median($history);
            $mad = self::median(array_map(fn ($v) => abs($v - $median), $history));
            if ($mad <= 0.0) {
                $mad = max(1.0, $median * 0.01); // identical history: treat 1 % as one deviation
            }
            $z = 0.6745 * (((float) $entry->net_kg) - $median) / $mad;
            if (abs($z) > $threshold) {
                yield [
                    "weight_outlier|{$entry->id}",
                    sprintf('Unusual load: %s net %s kg (usually about %s kg)', $entry->registration_no, $entry->net_kg, number_format($median, 0, '.', '')),
                    sprintf('Robust z-score %.1f over the last %d trips (limit %.1f). Check the weighment photos and the tare.', $z, count($history), $threshold),
                    ['gate_entry' => $entry->number, 'net_kg' => $entry->net_kg, 'median_kg' => $median, 'z' => round($z, 2), 'trips' => count($history)],
                    'approval.decide',
                ];
            }
        }
    }

    /** @return iterable<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: string}> */
    private function rateOutliers(CarbonInterface $since, CarbonInterface $until): iterable
    {
        $limit = (int) config('ricemill.anomalies.rate_deviation_bp');
        $deals = Sauda::query()->whereBetween('approved_at', [$since, $until])->with('product')->get();
        foreach ($deals as $sauda) {
            if ($sauda->direction === 'purchase') {
                $rates = Sauda::query()->where('direction', 'purchase')->where('product_id', $sauda->product_id)->whereKeyNot($sauda->id)
                    ->whereNotNull('approved_at')->where('approved_at', '>=', CarbonImmutable::instance($sauda->approved_at)->subDays(30))
                    ->where('approved_at', '<', $sauda->approved_at)->pluck('rate_paise_per_quintal')->map(fn ($v) => (float) $v)->all();
                if (count($rates) < 3) {
                    continue;
                }
                $median = self::median($rates);
                $dev = (int) round(($sauda->rate_paise_per_quintal - $median) * 10000 / $median);
                if (abs($dev) > $limit) {
                    yield [
                        "rate_outlier|{$sauda->id}",
                        sprintf('Purchase rate %s%% from the 30-day median: sauda %s', number_format($dev / 100, 1), $sauda->number),
                        sprintf('₹%s/qtl against a median of ₹%s/qtl over %d deals (limit ±%s%%).', number_format($sauda->rate_paise_per_quintal / 100, 2),
                            number_format($median / 100, 2), count($rates), number_format($limit / 100, 0)),
                        ['sauda' => $sauda->number, 'rate' => $sauda->rate_paise_per_quintal, 'median' => $median, 'deviation_bp' => $dev],
                        'deal.approve',
                    ];
                }
            } else {
                $list = $this->pricing->listRate($sauda->product, $sauda->approved_at);
                if ($list !== null && $sauda->rate_paise_per_quintal < $list) {
                    $dev = (int) round(($list - $sauda->rate_paise_per_quintal) * 10000 / $list);
                    if ($dev > $limit) {
                        yield [
                            "rate_outlier|{$sauda->id}",
                            sprintf('Sale %s%% below the list rate: sauda %s', number_format($dev / 100, 1), $sauda->number),
                            sprintf('₹%s/qtl against the list rate ₹%s/qtl.', number_format($sauda->rate_paise_per_quintal / 100, 2), number_format($list / 100, 2)),
                            ['sauda' => $sauda->number, 'rate' => $sauda->rate_paise_per_quintal, 'list' => $list, 'deviation_bp' => $dev],
                            'deal.approve',
                        ];
                    }
                }
            }
        }
    }

    /** @return iterable<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: string}> */
    private function afterHours(CarbonInterface $since, CarbonInterface $until): iterable
    {
        [$open, $close] = config('ricemill.anomalies.working_hours');
        $outside = function (CarbonInterface $at) use ($open, $close): bool {
            $t = CarbonImmutable::instance($at)->setTimezone('Asia/Kolkata')->format('H:i');

            return $t < $open || $t >= $close;
        };
        foreach (Weighment::query()->whereBetween('captured_at', [$since, $until])->get() as $w) {
            if ($outside($w->captured_at)) {
                $entry = GateEntry::query()->find($w->gate_entry_id);
                yield [
                    "after_hours|weighment|{$w->id}",
                    sprintf('Weighment at %s IST outside working hours (%s)', $w->captured_at->timezone('Asia/Kolkata')->format('d M H:i'), $entry?->registration_no),
                    sprintf('Working hours are %s–%s. %s %s kg on %s.', $open, $close, ucfirst($w->kind), $w->weight_kg, $entry?->number),
                    ['weighment_id' => $w->id, 'gate_entry' => $entry?->number],
                    'approval.decide',
                ];
            }
        }
        foreach (GateDeviceEvent::query()->where('kind', GateDeviceEvent::BARRIER_MANUAL)->whereBetween('occurred_at', [$since, $until])->get() as $e) {
            if ($outside($e->occurred_at)) {
                yield [
                    "after_hours|barrier|{$e->id}",
                    sprintf('Barrier opened by hand at %s IST, outside working hours', $e->occurred_at->timezone('Asia/Kolkata')->format('d M H:i')),
                    'Reason given: '.$e->reason,
                    ['device_event_id' => $e->id],
                    'approval.decide',
                ];
            }
        }
    }

    /** @return iterable<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: string}> */
    private function repeatPlateMismatch(CarbonInterface $until): iterable
    {
        $limit = (int) config('ricemill.anomalies.plate_mismatch_repeat');
        $reads = GateDeviceEvent::query()->where('kind', GateDeviceEvent::PLATE_READ)->where('occurred_at', '>=', CarbonImmutable::instance($until)->subDays(30))
            ->whereNotNull('gate_entry_id')->get()->filter(fn (GateDeviceEvent $e) => ($e->data['mismatch'] ?? false) === true);
        $byVehicle = $reads->groupBy(fn (GateDeviceEvent $e) => (string) ($e->data['typed'] ?? ''));
        foreach ($byVehicle as $vehicle => $events) {
            if ($vehicle !== '' && $events->count() >= $limit) {
                yield [
                    "repeat_plate_mismatch|{$vehicle}|{$events->count()}",
                    sprintf('Vehicle %s: plate read differed from the typed number %d times in 30 days', $vehicle, $events->count()),
                    'Plates read: '.$events->pluck('plate')->unique()->implode(', ').'. A swapped or cloned number plate is possible.',
                    ['vehicle' => $vehicle, 'count' => $events->count()],
                    'approval.decide',
                ];
            }
        }
    }

    /** @return iterable<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: string}> */
    private function manualOverrides(CarbonInterface $until): iterable
    {
        $limit = (int) config('ricemill.anomalies.manual_override_repeat');
        $rows = Weighment::query()->where('source', Weighment::SOURCE_MANUAL)->where('captured_at', '>=', CarbonImmutable::instance($until)->subDays(30))
            ->whereNotNull('approval_request_id')->get();
        $byMaker = $rows->groupBy(fn (Weighment $w) => (string) ApprovalRequest::query()->whereKey($w->approval_request_id)->value('maker_person_id'));
        foreach ($byMaker as $person => $items) {
            if ($person !== '' && $items->count() >= $limit) {
                yield [
                    "manual_overrides|{$person}|{$items->count()}",
                    sprintf('%d manual weight overrides requested by the same person in 30 days', $items->count()),
                    'Typed weights should be rare (indicator failures). Check the reasons and the indicator.',
                    ['person_id' => $person, 'count' => $items->count()],
                    'approval.decide',
                ];
            }
        }
    }

    /** @return iterable<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: string}> */
    private function approvalsSameParty(CarbonInterface $until): iterable
    {
        $limit = (int) config('ricemill.anomalies.approvals_same_party');
        $requests = ApprovalRequest::query()->where('action', 'payment.release')->where('status', ApprovalRequest::STATUS_EXECUTED)
            ->where('decided_at', '>=', CarbonImmutable::instance($until)->subDays(7))->get();
        $groups = [];
        foreach ($requests as $r) {
            $party = Payment::query()->whereKey($r->payload['payment_id'] ?? null)->value('party_id');
            if ($party !== null && $r->checker_person_id !== null) {
                $groups["{$r->checker_person_id}|{$party}"][] = $r->id;
            }
        }
        foreach ($groups as $key => $ids) {
            if (count($ids) >= $limit) {
                [$person, $party] = explode('|', $key);
                $name = Party::query()->whereKey($party)->value('name');
                yield [
                    "approvals_same_party|{$key}|".count($ids),
                    sprintf('%d payments to %s released by the same approver in 7 days', count($ids), $name),
                    'Many releases to one party by one person can hide split payments. Review the bills behind them.',
                    ['approver_person_id' => $person, 'party' => $name, 'count' => count($ids)],
                    'approval.decide',
                ];
            }
        }
    }

    /** @return iterable<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: string}> */
    private function yieldDrift(): iterable
    {
        $k = (int) config('ricemill.anomalies.yield_drift_batches');
        $byPaddy = ProductionBatch::query()->where('status', ProductionBatch::CLOSED)->orderByDesc('closed_at')->get()->groupBy('paddy_product_id');
        foreach ($byPaddy as $batches) {
            $recent = $batches->take($k);
            if ($recent->count() < $k) {
                continue;
            }
            $common = null;
            foreach ($recent as $b) {
                /** @var array<string, array<string, mixed>> $outturn */
                $outturn = (array) ($b->yield_snapshot['outturn'] ?? []);
                $out = array_keys(array_filter($outturn, fn (array $r) => (bool) ($r['out_of_range'] ?? false)));
                $common = $common === null ? $out : array_values(array_intersect($common, $out));
            }
            foreach ($common ?? [] as $productId) {
                $last = $recent->first();
                $row = $last->yield_snapshot['outturn'][$productId];
                yield [
                    "yield_drift|{$productId}|{$last->id}",
                    sprintf('%s outturn outside the standard in the last %d batches of %s', $row['product'] ?? 'Output', $k, $last->paddy?->name),
                    sprintf('Latest batch %s: %s%% against %s%%. A persistent drift points at the machine, the paddy grade or unrecorded output.',
                        $last->number, number_format($row['bp'] / 100, 2), number_format(((int) $row['expected_bp']) / 100, 2)),
                    ['batches' => $recent->pluck('number')->all(), 'product_id' => $productId],
                    'batch.manage',
                ];
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private function raise(string $rule, string $key, string $title, string $body, array $data, string $permission): bool
    {
        $subjectId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'anomaly:'.$key)->toString();
        if (Alert::query()->where('type', $rule)->where('subject_id', $subjectId)->exists()) {
            return false;
        }
        $alert = $this->alerts->raise($rule, 'warning', $title, $body, null, $data + ['rule' => $rule], $permission);
        $alert->forceFill(['subject_type' => 'anomaly', 'subject_id' => $subjectId])->save();

        return true;
    }

    /** @param  list<float>  $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}

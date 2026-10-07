<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Procurement\SaudaService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\LabTest;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Support\Values\IndianIds;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle visits at the gate. Everything recorded here is an append-only fact (gate event,
 * weighment, lab test); the gate entry's status, weights and deductions are re-projected from
 * those facts after every change, so two offline devices can never overwrite each other.
 *
 * $strict = true (online screens): steps must follow the flow.
 * $strict = false (device sync): out-of-order facts are kept and marked as anomalies.
 */
final class GateService
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly DeductionCalculator $deductions,
        private readonly TareCheck $tareCheck,
        private readonly SaudaService $saudas,
        private readonly ActorContext $actor,
    ) {}

    // ------------------------------------------------------------------ arrival

    /** @param  array<string, mixed>  $data */
    public function arrive(array $data, ?string $id = null, ?CarbonInterface $occurredAt = null): GateEntry
    {
        $this->require('gate.entry');
        $occurredAt ??= now();

        return DB::transaction(function () use ($data, $id, $occurredAt): GateEntry {
            $registration = IndianIds::normalizeVehicleNumber((string) $data['registration_no']);
            $vehicle = Vehicle::query()->where('registration_no', $registration)->first();
            $flags = [];
            if ($vehicle === null) {
                $flags[] = 'unknown_vehicle';
            } elseif ($vehicle->is_blocked) {
                $flags[] = 'blocked_vehicle';
            }

            $sauda = isset($data['sauda_id']) ? Sauda::query()->find($data['sauda_id']) : null;
            if ($sauda !== null && ! $sauda->isOpenForDelivery()) {
                $flags[] = 'sauda_not_open';
            }

            $entry = new GateEntry([
                'direction' => $data['direction'] ?? 'inbound',
                'purpose' => $data['purpose'] ?? 'paddy_purchase',
                'vehicle_id' => $vehicle?->id,
                'registration_no' => $registration,
                'driver_id' => $data['driver_id'] ?? $vehicle?->default_driver_id,
                'driver_name' => $data['driver_name'] ?? null,
                'party_id' => $data['party_id'] ?? $sauda?->party_id,
                'sauda_id' => $sauda?->id,
                'product_id' => $data['product_id'] ?? $sauda?->product_id,
                'bag_count' => $data['bag_count'] ?? null,
                'arrived_at' => $occurredAt,
            ]);
            if ($id !== null) {
                $entry->id = $id;
            }
            $entry->number = $this->numbers->next(DocumentNumbers::GATE, $occurredAt);
            $entry->status = 'arrived';
            $entry->flags = $flags;
            $entry->save();

            $this->appendEvent($entry, 'arrived', ['flags' => $flags], $occurredAt, null);

            return $entry;
        });
    }

    // ------------------------------------------------------------------ weighbridge

    /**
     * A weight from the indicator. `readings` is the stable-window evidence sent by the agent:
     * {"samples": ["25480.000", …], "window_ms": 3000}.
     *
     * @param  array<string, mixed>|null  $readings
     */
    public function recordWeighment(
        GateEntry $entry,
        string $kind,
        string $weightKg,
        ?Scale $scale,
        ?array $readings,
        ?string $photoSha256,
        ?CarbonInterface $capturedAt = null,
        bool $strict = true,
        ?string $id = null,
        string $source = Weighment::SOURCE_INDICATOR,
        ?string $reason = null,
        ?string $approvalRequestId = null,
    ): Weighment {
        if ($source === Weighment::SOURCE_INDICATOR) {
            $this->require('weighment.capture');
        }
        if (! in_array($kind, [Weighment::GROSS, Weighment::TARE], true)) {
            throw new BusinessRuleViolation("Unknown weighment kind {$kind}.");
        }
        $capturedAt ??= now();
        $weight = Weight::kg($weightKg);

        return DB::transaction(function () use ($entry, $kind, $weight, $scale, $readings, $photoSha256, $capturedAt, $strict, $id, $source, $reason, $approvalRequestId): Weighment {
            $entry = $this->lock($entry);
            $flags = [];

            if (bccomp($weight->kg, '0', 3) <= 0) {
                throw new BusinessRuleViolation('A weight must be greater than zero.');
            }
            if ($scale !== null) {
                if ($weight->compare(Weight::kg($scale->capacity_kg)) > 0) {
                    throw new BusinessRuleViolation("Weight exceeds the capacity of scale {$scale->code}.");
                }
                if (! $scale->hasValidStampingOn(CarbonImmutable::instance($capturedAt))) {
                    if (config('ricemill.gate.block_uncertified_scale')) {
                        throw new BusinessRuleViolation("Scale {$scale->code} has no valid stamping certificate.");
                    }
                    $flags[] = 'scale_certificate_invalid';
                }
            }

            if ($source === Weighment::SOURCE_INDICATOR) {
                if (! $this->isStable($readings, $weight, $scale)) {
                    $flags[] = 'stability_unverified';
                }
                if ($photoSha256 === null) {
                    $flags[] = 'no_photo';
                }
            } else {
                $flags[] = 'manual_override';
            }

            $previous = Weighment::query()->where('gate_entry_id', $entry->id)->where('kind', $kind)
                ->whereNotIn('id', Weighment::query()->whereNotNull('supersedes_id')->select('supersedes_id'))
                ->orderByDesc('captured_at')->first();
            if ($previous !== null) {
                $flags[] = 'reweighed';
            }

            if ($kind === Weighment::TARE) {
                $deviation = $this->tareCheck->check($entry->registration_no, $weight, $entry->id);
                if ($deviation !== null) {
                    $flags[] = 'tare_deviation';
                    $readings = ($readings ?? []) + ['tare_check' => $deviation];
                }
            }

            $weighment = new Weighment([
                'gate_entry_id' => $entry->id,
                'kind' => $kind,
                'weight_kg' => $weight->kg,
                'scale_id' => $scale?->id,
                'source' => $source,
                'readings' => $readings,
                'photo_sha256' => $photoSha256,
                'supersedes_id' => $previous?->id,
                'reason' => $reason,
                'approval_request_id' => $approvalRequestId,
                'flags' => $flags,
                'device_id' => $this->actor->deviceId(),
                'captured_at' => $capturedAt,
            ]);
            if ($id !== null) {
                $weighment->id = $id;
            }
            $weighment->entity_id = $entry->entity_id;
            $weighment->created_at = now();
            $weighment->save();

            $this->appendEvent($entry, $kind.'_weighed', ['weighment_id' => $weighment->id, 'weight_kg' => $weight->kg, 'flags' => $flags], $capturedAt, $strict);
            $this->project($entry);

            return $weighment;
        });
    }

    // ------------------------------------------------------------------ lab

    /** @param  array<string, mixed>  $values  moisture_bp, foreign_matter_bp, …, grade, remarks, sample_code */
    public function recordLabTest(GateEntry $entry, array $values, ?CarbonInterface $testedAt = null, ?string $id = null): LabTest
    {
        $this->require('lab.test');

        return DB::transaction(function () use ($entry, $values, $testedAt, $id): LabTest {
            $entry = $this->lock($entry);
            $previous = LabTest::query()->where('gate_entry_id', $entry->id)->orderByDesc('tested_at')->first();

            $test = new LabTest(array_intersect_key($values, array_flip([
                'sample_code', 'moisture_bp', 'foreign_matter_bp', 'broken_bp', 'damaged_bp', 'immature_bp', 'chalky_bp', 'grade', 'remarks',
            ])) + [
                'gate_entry_id' => $entry->id,
                'supersedes_id' => $previous?->id,
                'device_id' => $this->actor->deviceId(),
                'tested_at' => $testedAt ?? now(),
            ]);
            if ($id !== null) {
                $test->id = $id;
            }
            $test->entity_id = $entry->entity_id;
            $test->created_at = now();
            $test->save();

            return $test;
        });
    }

    /**
     * Accept or reject the lot before unloading. The applicable deduction rules are frozen into
     * the decision (true record of what was agreed at that moment).
     */
    public function decideLab(GateEntry $entry, bool $accept, ?string $note = null, ?CarbonInterface $at = null, bool $strict = true): GateEvent
    {
        $this->require('lab.decide');

        return DB::transaction(function () use ($entry, $accept, $note, $at, $strict): GateEvent {
            $entry = $this->lock($entry);
            $test = LabTest::query()->where('gate_entry_id', $entry->id)->orderByDesc('tested_at')->first()
                ?? throw new BusinessRuleViolation('Record a lab test before deciding.');

            $rules = $entry->product_id
                ? DeductionRule::query()->where('product_id', $entry->product_id)->where('status', 'active')->get()
                : collect();
            $preview = $this->deductions->calculate('0', $entry->bag_count, $test->measurements(), $rules);

            if ($accept && $preview['reject'] && ($note === null || trim($note) === '')) {
                throw new BusinessRuleViolation('This sample is above a reject limit. Accepting it needs a note explaining why.');
            }

            $event = $this->appendEvent($entry, $accept ? 'lab_accepted' : 'lab_rejected', [
                'lab_test_id' => $test->id,
                'measurements' => $test->measurements(),
                'grade' => $test->grade,
                'above_reject_limit' => $preview['reject'],
                'note' => $note,
                'rules' => $rules->map(fn (DeductionRule $r) => $r->only(['id', 'parameter', 'method', 'free_limit_bp', 'factor', 'reject_above_bp', 'status']))->values()->all(),
            ], $at ?? now(), $strict);
            $this->project($entry);

            return $event;
        });
    }

    // ------------------------------------------------------------------ yard & exit

    public function markUnloaded(GateEntry $entry, ?CarbonInterface $at = null, bool $strict = true): GateEvent
    {
        $this->require('gate.entry');

        return $this->simpleEvent($entry, 'unloaded', $at, $strict, function (GateEntry $e): void {
            if ($e->direction === 'inbound' && ! $this->hasEvent($e, 'lab_accepted')) {
                throw new BusinessRuleViolation('Unload only after the lab has accepted the lot.');
            }
        });
    }

    /**
     * Freezes the weighment result (gross, tare, net, deductions, payable) into the slip event
     * and, for an accepted inbound lot against a sauda, books the delivery on the deal.
     */
    public function issueSlip(GateEntry $entry, bool $strict = true): GateEvent
    {
        $this->require('weighment.capture');

        return DB::transaction(function () use ($entry, $strict): GateEvent {
            $entry = $this->lock($entry);
            if ($this->hasEvent($entry, 'slip_issued')) {
                throw new BusinessRuleViolation('A slip was already issued for this vehicle.');
            }
            $this->project($entry);
            if ($entry->gross_kg === null || $entry->tare_kg === null) {
                throw new BusinessRuleViolation('Both gross and tare weights are needed for a slip.');
            }
            if (bccomp((string) $entry->net_kg, '0', 3) <= 0) {
                throw new BusinessRuleViolation('Net weight is not positive; check the weighments.');
            }

            $accepted = $this->hasEvent($entry, 'lab_accepted') && ! $this->latestLabDecisionIsReject($entry);
            $snapshot = [
                'gross_kg' => $entry->gross_kg, 'tare_kg' => $entry->tare_kg, 'net_kg' => $entry->net_kg,
                'deduction_kg' => $entry->deduction_kg, 'payable_kg' => $entry->payable_kg,
                'rate_cut_paise_per_quintal' => $entry->rate_cut_paise_per_quintal, 'lab_accepted' => $accepted,
                'flags' => $entry->flags ?? [],
            ];
            $event = $this->appendEvent($entry, 'slip_issued', $snapshot, now(), $strict);

            if ($entry->direction === 'inbound' && $accepted && $entry->sauda_id !== null && $entry->payable_kg !== null) {
                $sauda = Sauda::query()->find($entry->sauda_id);
                if ($sauda !== null && $sauda->isOpenForDelivery()) {
                    $this->saudas->recordDelivery($sauda, $entry, Weight::kg($entry->payable_kg));
                }
            }
            $this->project($entry);

            return $event;
        });
    }

    public function exit(GateEntry $entry, ?CarbonInterface $at = null, bool $strict = true): GateEvent
    {
        $this->require('gate.entry');

        return $this->simpleEvent($entry, 'exited', $at, $strict, function (GateEntry $e) use ($strict): void {
            if ($strict && ! $this->hasEvent($e, 'slip_issued') && ! $this->hasEvent($e, 'lab_rejected')) {
                throw new BusinessRuleViolation('Issue the weighment slip before the vehicle leaves.');
            }
        });
    }

    public function cancel(GateEntry $entry, string $reason): GateEvent
    {
        $this->require('gate.entry');

        return DB::transaction(function () use ($entry, $reason): GateEvent {
            $entry = $this->lock($entry);
            if ($this->hasEvent($entry, 'slip_issued')) {
                throw new BusinessRuleViolation('A vehicle with an issued slip cannot be cancelled.');
            }
            $event = $this->appendEvent($entry, 'cancelled', ['reason' => $reason], now(), false);
            $this->project($entry);

            return $event;
        });
    }

    /** Generic event coming from a device (sync); lenient ordering. */
    public function recordDeviceEvent(GateEntry $entry, string $event, CarbonInterface $at): GateEvent
    {
        return match ($event) {
            'unloaded' => $this->markUnloaded($entry, $at, false),
            'exited' => $this->exit($entry, $at, false),
            default => throw new BusinessRuleViolation("Event {$event} cannot be sent by a device."),
        };
    }

    // ------------------------------------------------------------------ projection

    /** Recomputes status, effective weights, deductions and flags from the facts. */
    public function project(GateEntry $entry): GateEntry
    {
        $events = GateEvent::query()->where('gate_entry_id', $entry->id)->orderBy('occurred_at')->orderBy('recorded_at')->get();
        $names = $events->pluck('event')->all();

        $effective = fn (string $kind) => Weighment::query()->where('gate_entry_id', $entry->id)->where('kind', $kind)
            ->whereNotIn('id', Weighment::query()->where('gate_entry_id', $entry->id)->whereNotNull('supersedes_id')->select('supersedes_id'))
            ->orderByDesc('captured_at')->first();
        $gross = $effective(Weighment::GROSS);
        $tare = $effective(Weighment::TARE);

        $entry->gross_kg = $gross?->weight_kg;
        $entry->tare_kg = $tare?->weight_kg;
        $entry->net_kg = $gross && $tare ? Weight::kg($gross->weight_kg)->minus(Weight::kg($tare->weight_kg))->kg : null;

        // Deductions from the latest lab decision, with the rules frozen in it.
        $decision = $events->whereIn('event', ['lab_accepted', 'lab_rejected'])->last();
        $entry->deduction_kg = null;
        $entry->payable_kg = $entry->net_kg;
        $entry->rate_cut_paise_per_quintal = null;
        if ($decision !== null && $decision->event === 'lab_accepted' && $entry->net_kg !== null && bccomp($entry->net_kg, '0', 3) > 0) {
            /** @var list<array<string, mixed>> $frozen rules as they were when the decision was taken */
            $frozen = (array) ($decision->data['rules'] ?? []);
            $rules = array_map(fn (array $r) => (new DeductionRule)->forceFill($r), $frozen);
            $result = $this->deductions->calculate($entry->net_kg, $entry->bag_count, $decision->data['measurements'] ?? [], $rules);
            $entry->deduction_kg = $result['deduction_kg'];
            $entry->payable_kg = Weight::kg($entry->net_kg)->minus(Weight::kg($result['deduction_kg']))->kg;
            $entry->rate_cut_paise_per_quintal = $result['rate_cut_paise_per_quintal'];
        }

        // Status: cancelled / furthest step reached along the flow / lab_rejected.
        if (in_array('cancelled', $names, true)) {
            $entry->status = 'cancelled';
        } elseif (in_array('exited', $names, true)) {
            $entry->status = 'exited';
        } else {
            $reached = 'arrived';
            foreach ($entry->flow() as $step) {
                if (in_array($step, $names, true)) {
                    $reached = $step;
                }
            }
            if ($decision?->event === 'lab_rejected' && in_array($reached, ['gross_weighed', 'arrived'], true)) {
                $reached = 'lab_rejected';
            }
            $entry->status = $reached;
        }
        $entry->exited_at = $events->firstWhere('event', 'exited')?->occurred_at;

        /** @var Collection<int, string|null> $flags */
        $flags = collect($entry->flags ?? [])
            ->merge(Weighment::query()->where('gate_entry_id', $entry->id)->pluck('flags')->flatten())
            ->merge($events->whereNotNull('anomaly')->map(fn (GateEvent $e) => 'out_of_order'))
            ->reject(fn ($f) => $f === 'reweighed' || $f === null);
        if ($entry->net_kg !== null && bccomp($entry->net_kg, '0', 3) <= 0) {
            $flags->push('net_not_positive');
        }
        $entry->flags = $flags->unique()->values()->all();
        $entry->save();

        return $entry;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $data
     */
    private function appendEvent(GateEntry $entry, string $event, array $data, CarbonInterface $at, ?bool $strict): GateEvent
    {
        $anomaly = $this->orderAnomaly($entry, $event);
        if ($anomaly !== null && $strict === true) {
            throw new BusinessRuleViolation($anomaly);
        }

        return GateEvent::query()->create([
            'entity_id' => $entry->entity_id,
            'gate_entry_id' => $entry->id,
            'event' => $event,
            'data' => $data,
            'anomaly' => $anomaly,
            'actor_login_account_id' => $this->actor->accountId(),
            'device_id' => $this->actor->deviceId(),
            'occurred_at' => $at,
            'recorded_at' => now(),
        ]);
    }

    /** Which earlier step of the flow is missing, if any. */
    private function orderAnomaly(GateEntry $entry, string $event): ?string
    {
        if (in_array($event, ['arrived', 'cancelled'], true)) {
            return null;
        }
        if (in_array($this->lastEventName($entry), ['exited', 'cancelled'], true)) {
            return "The vehicle has already {$this->lastEventName($entry)}.";
        }
        $flow = $entry->flow();
        $position = array_search($event === 'lab_rejected' ? 'lab_accepted' : $event, $flow, true);
        if ($position === false || $position === 0) {
            return null;
        }
        $required = $flow[$position - 1];
        if ($event === 'exited' && $this->hasEvent($entry, 'lab_rejected')) {
            return null; // a rejected lot leaves without unloading
        }
        if ($event === 'tare_weighed' && $entry->direction === 'inbound' && $this->hasEvent($entry, 'lab_rejected')) {
            return null; // rejected lot may be re-weighed empty-or-not before leaving
        }

        return $this->hasEvent($entry, $required) ? null : "'{$event}' recorded before '{$required}'.";
    }

    private function hasEvent(GateEntry $entry, string $event): bool
    {
        return GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', $event)->exists();
    }

    private function lastEventName(GateEntry $entry): ?string
    {
        return GateEvent::query()->where('gate_entry_id', $entry->id)->whereIn('event', ['exited', 'cancelled'])->value('event');
    }

    private function latestLabDecisionIsReject(GateEntry $entry): bool
    {
        return GateEvent::query()->where('gate_entry_id', $entry->id)->whereIn('event', ['lab_accepted', 'lab_rejected'])
            ->orderByDesc('occurred_at')->orderByDesc('recorded_at')->value('event') === 'lab_rejected';
    }

    /** @param  callable(GateEntry): void  $guard */
    private function simpleEvent(GateEntry $entry, string $event, ?CarbonInterface $at, bool $strict, callable $guard): GateEvent
    {
        return DB::transaction(function () use ($entry, $event, $at, $strict, $guard): GateEvent {
            $entry = $this->lock($entry);
            if ($strict) {
                $guard($entry);
            }
            $gateEvent = $this->appendEvent($entry, $event, [], $at ?? now(), $strict);
            $this->project($entry);

            return $gateEvent;
        });
    }

    /** @param  array<string, mixed>|null  $readings */
    private function isStable(?array $readings, Weight $weight, ?Scale $scale): bool
    {
        $samples = array_values(array_filter((array) ($readings['samples'] ?? []), 'is_string'));
        if (count($samples) < (int) config('ricemill.gate.min_stable_samples')) {
            return false;
        }
        try {
            $values = array_map(fn (string $s) => Weight::kg($s), $samples);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $step = Weight::kg($scale !== null ? $scale->resolution_kg : '10');
        $min = $max = $values[0];
        foreach ($values as $v) {
            $min = $v->compare($min) < 0 ? $v : $min;
            $max = $v->compare($max) > 0 ? $v : $max;
        }

        // Spread within one scale step, and the recorded weight is inside the window.
        return $max->minus($min)->compare($step) <= 0
            && $weight->compare($min) >= 0 && $weight->compare($max) <= 0;
    }

    private function lock(GateEntry $entry): GateEntry
    {
        /** @var GateEntry */
        return GateEntry::query()->lockForUpdate()->findOrFail($entry->id);
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}

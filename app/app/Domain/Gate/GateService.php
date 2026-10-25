<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Approvals\SelfApproval;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Inventory\StockLedger;
use App\Domain\Messaging\PartyEventMessages;
use App\Domain\Procurement\SaudaService;
use App\Domain\Saas\PlanLimits;
use App\Domain\Sales\SalesBillService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Models\Gate\LabTest;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Godown;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;
use App\Support\Values\PakistanIds;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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
        private readonly StockLedger $stock,
        private readonly PurchaseBillService $bills,
        private readonly SalesBillService $salesBills,
        private readonly ActorContext $actor,
        private readonly LabStandards $standards,
    ) {}

    // ------------------------------------------------------------------ arrival

    /** @param  array<string, mixed>  $data */
    public function arrive(array $data, ?string $id = null, ?CarbonInterface $occurredAt = null): GateEntry
    {
        $this->require('gate.entry');
        $occurredAt ??= now();

        return DB::transaction(function () use ($data, $id, $occurredAt): GateEntry {
            // The plan's vehicles-a-month limit, checked inside the transaction that registers the vehicle
            // (the customer's row stays locked until it is saved, so two arrivals cannot both take the last place).
            app(PlanLimits::class)->assertCanAddVehicle(app(TenantContext::class)->entityId());
            $noPlate = (bool) ($data['no_plate'] ?? false);
            $type = isset($data['vehicle_type']) && $data['vehicle_type'] !== '' ? (string) $data['vehicle_type'] : null;
            if ($type !== null && ! in_array($type, Vehicle::TYPES, true)) {
                throw new BusinessRuleViolation('Unknown kind of vehicle.');
            }
            $description = trim((string) ($data['vehicle_description'] ?? ''));
            $flags = [];
            if ($noPlate) {
                // No number plate (bail gari, donkey cart, bicycle…): known by its kind and a description.
                if ($type === null || $description === '') {
                    throw new BusinessRuleViolation('For a vehicle with no number plate, give its kind and a description (owner, village, colour…).');
                }
                $registration = null;
                $vehicle = null;
                $flags[] = 'no_number_plate';
            } else {
                $registration = PakistanIds::normalizeVehicleNumber((string) ($data['registration_no'] ?? ''));
                if (! PakistanIds::isValidVehicleNumber($registration)) {
                    throw new BusinessRuleViolation('Not a valid registration number. A vehicle with no number plate: tick "No number plate".');
                }
                $vehicle = Vehicle::query()->where('registration_no', $registration)->first();
                if ($vehicle === null) {
                    $flags[] = 'unknown_vehicle';
                } elseif ($vehicle->is_blocked) {
                    $flags[] = 'blocked_vehicle';
                }
            }

            // Outbound against a dispatch order: the order decides buyer, deal and product.
            $order = isset($data['dispatch_order_id']) ? DispatchOrder::query()->lockForUpdate()->find($data['dispatch_order_id']) : null;
            if (isset($data['dispatch_order_id']) && $order === null) {
                throw new BusinessRuleViolation('Dispatch order not found.');
            }
            if ($order !== null) {
                $data = ['direction' => 'outbound', 'purpose' => 'dispatch', 'sauda_id' => $order->sauda_id,
                    'party_id' => $order->party_id, 'product_id' => $order->product_id, 'bag_count' => $order->bag_count] + $data;
                if ($order->status !== DispatchOrder::OPEN) {
                    $flags[] = 'dispatch_order_not_open';
                }
            }

            $sauda = isset($data['sauda_id']) ? Sauda::query()->find($data['sauda_id']) : null;
            if ($sauda !== null && ! $sauda->isOpenForDelivery()) {
                $flags[] = 'sauda_not_open';
            }

            $entry = new GateEntry([
                'direction' => $data['direction'] ?? 'inbound',
                'purpose' => $data['purpose'] ?? 'paddy_purchase',
                'vehicle_id' => $vehicle?->id,
                'registration_no' => $registration ?? '',
                'vehicle_type' => $type ?? $vehicle?->vehicle_type,
                'no_plate' => $noPlate,
                'vehicle_description' => $description === '' ? null : mb_substr($description, 0, 160),
                'goods_description' => isset($data['goods_description']) && trim((string) $data['goods_description']) !== '' ? mb_substr(trim((string) $data['goods_description']), 0, 160) : null,
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
            // The day's token, in order of arrival (first come, first served).
            $day = CarbonImmutable::instance($occurredAt)->timezone('Asia/Karachi')->toDateString();
            $entry->token_date = Carbon::parse($day, 'Asia/Karachi')->startOfDay();
            $entry->token_no = $this->numbers->nextDaily('TOKEN', $day,
                fn () => (int) GateEntry::query()->whereDate('token_date', $day)->max('token_no'));
            if ($noPlate) {
                $entry->registration_no = substr(GateEntry::NO_PLATE_PREFIX.preg_replace('/\D/', '', $entry->number), 0, 24);
            }
            $entry->status = 'arrived';
            $entry->flags = $flags;
            if ($order !== null && $order->status === DispatchOrder::OPEN) {
                $entry->dispatch_order_id = $order->id;
            }
            $entry->save();
            if ($entry->dispatch_order_id !== null && $order !== null) {
                $order->forceFill(['status' => DispatchOrder::LOADING, 'gate_entry_id' => $entry->id])->save();
            }

            $this->appendEvent($entry, 'arrived', ['flags' => $flags], $occurredAt, null);

            return $entry;
        });
    }

    // ------------------------------------------------------------------ weighbridge

    /**
     * A weight from the indicator. `readings` is the stable-window evidence sent by the agent:
     * {"samples": ["25480.000", …], "window_ms": 3000}. `$extraFlags` mark how it was taken
     * (`live_screen`: from the Live weight screen).
     *
     * @param  array<string, mixed>|null  $readings
     * @param  list<string>  $extraFlags
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
        array $extraFlags = [],
    ): Weighment {
        if ($source === Weighment::SOURCE_INDICATOR) {
            $this->require('weighment.capture');
        }
        if (! in_array($kind, [Weighment::GROSS, Weighment::TARE], true)) {
            throw new BusinessRuleViolation("Unknown weighment kind {$kind}.");
        }
        $capturedAt ??= now();
        $weight = Weight::kg($weightKg);

        return DB::transaction(function () use ($entry, $kind, $weight, $scale, $readings, $photoSha256, $capturedAt, $strict, $id, $source, $reason, $approvalRequestId, $extraFlags): Weighment {
            $entry = $this->lock($entry);
            $flags = array_values($extraFlags);

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

    /**
     * A lab result. `results` holds the configured lab tests (code => basis points, LabStandards); the
     * older fixed columns (moisture_bp, …) are still accepted. Each result is read against its standard
     * and grace limit; any result above its grace limit rejects the lot at once, with the reason — it
     * can then come in only through negotiation (acceptRejectedLot).
     *
     * @param  array<string, mixed>  $values  results, moisture_bp, …, grade, remarks, sample_code
     */
    public function recordLabTest(GateEntry $entry, array $values, ?CarbonInterface $testedAt = null, ?string $id = null): LabTest
    {
        $this->require('lab.test');

        return DB::transaction(function () use ($entry, $values, $testedAt, $id): LabTest {
            $entry = $this->lock($entry);
            if ($entry->direction === 'inbound' && $this->hasEvent($entry, 'gross_weighed')) {
                throw new BusinessRuleViolation('This lot has already been weighed; the lab result belongs before weighing.');
            }
            $previous = LabTest::query()->where('gate_entry_id', $entry->id)->latestFirst()->first();
            // A second result corrects the first; once the lab has decided, the correction needs its reason.
            $why = trim((string) ($values['correction_reason'] ?? ''));
            $decided = $this->hasEvent($entry, 'lab_accepted') || $this->hasEvent($entry, 'lab_rejected');
            if ($previous !== null && $decided && $why === '') {
                throw new BusinessRuleViolation('The lab has already decided on this lot. Give the reason for correcting the result.');
            }
            $known = $this->standards->parametersFor($entry->product_id);
            $results = [];
            foreach ((array) ($values['results'] ?? []) as $code => $bp) {
                if ($bp === null || $bp === '') {
                    continue;
                }
                if (! $known->has($code)) {
                    throw new BusinessRuleViolation("Unknown lab test {$code}.");
                }
                if (! is_numeric($bp) || (int) $bp < 0 || (int) $bp > 10000) {
                    throw new BusinessRuleViolation("The result for {$known[$code]->name_en} must be between 0 and 100 %.");
                }
                $results[(string) $code] = (int) $bp;
            }
            $evaluation = $this->standards->evaluate($results, $entry->product_id);

            $test = new LabTest(array_intersect_key($values, array_flip([
                'sample_code', 'moisture_bp', 'foreign_matter_bp', 'broken_bp', 'damaged_bp', 'immature_bp', 'chalky_bp', 'grade', 'remarks',
            ])) + [
                'gate_entry_id' => $entry->id,
                'supersedes_id' => $previous?->id,
                'device_id' => $this->actor->deviceId(),
                'tested_at' => $testedAt ?? now(),
                'results' => $results === [] ? null : $results,
                'evaluation' => $evaluation === [] ? null : $evaluation,
            ]);
            if ($id !== null) {
                $test->id = $id;
            }
            $test->entity_id = $entry->entity_id;
            $test->created_at = now();
            $test->save();
            if ($previous !== null) {
                $this->appendEvent($entry, 'lab_corrected', [
                    'lab_test_id' => $test->id, 'previous_lab_test_id' => $previous->id, 'reason' => $why === '' ? 'Re-tested before the decision' : $why,
                    'previous_results' => $previous->results, 'results' => $results, 'cleared_decision' => $decided,
                ], $testedAt ?? now(), false);
                $this->project($entry);
            }

            // Beyond a grace limit: rejected at once, with the reason.
            $over = array_values(array_filter($evaluation, fn (array $r) => $r['verdict'] === LabStandards::OVER));
            if ($over !== []) {
                $this->appendEvent($entry, 'lab_rejected', [
                    'lab_test_id' => $test->id, 'automatic' => true, 'measurements' => $test->measurements(), 'grade' => $test->grade,
                    'note' => 'Rejected automatically: '.implode('; ', array_map(fn (array $r) => LabStandards::describe($r), $over)),
                    'over_grace' => $over,
                ], $testedAt ?? now(), false);
                $this->project($entry);
            }

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
            $test = LabTest::query()->where('gate_entry_id', $entry->id)->latestFirst()->first()
                ?? throw new BusinessRuleViolation('Record a lab test before deciding.');
            if ($accept && $this->standards->overGrace($entry) !== []) {
                throw new BusinessRuleViolation('A result is above its grace limit, so the lot was rejected. It can come in only through negotiation, approved by the Purchase Manager.');
            }
            [$data, $preview] = $this->labDecisionData($entry, $test, $note);

            if ($accept && $preview['reject'] && ($note === null || trim($note) === '')) {
                throw new BusinessRuleViolation('This sample is above a reject limit. Accepting it needs a note explaining why.');
            }

            $event = $this->appendEvent($entry, $accept ? 'lab_accepted' : 'lab_rejected', $data, $at ?? now(), $strict);
            $this->project($entry);

            return $event;
        });
    }

    /**
     * What a lab decision freezes: the result, its reading against the limits and the deduction rules
     * as they are at that moment.
     *
     * @return array{0: array<string, mixed>, 1: array{deduction_kg: string, rate_cut_paise_per_maund: int, reject: bool, lines: list<array<string, mixed>>}}
     */
    private function labDecisionData(GateEntry $entry, LabTest $test, ?string $note): array
    {
        $rules = $entry->product_id
            ? DeductionRule::query()->where('product_id', $entry->product_id)->where('status', 'active')->get()
            : collect();
        $preview = $this->deductions->calculate('0', $entry->bag_count, $test->measurements(), $rules);

        return [[
            'lab_test_id' => $test->id,
            'measurements' => $test->measurements(),
            'evaluation' => $test->evaluation ?? [],
            'grade' => $test->grade,
            'above_reject_limit' => $preview['reject'],
            'note' => $note,
            'rules' => $rules->map(fn (DeductionRule $r) => $r->only(['id', 'parameter', 'method', 'free_limit_bp', 'factor', 'reject_above_bp', 'status']))->values()->all(),
        ], $preview];
    }

    // ------------------------------------------------------------------ negotiation and settlement

    /** The price a vehicle is bought at, last accepted (null until a price is accepted). */
    public function acceptedPrice(GateEntry $entry): ?GatePriceVersion
    {
        return GatePriceVersion::query()->where('gate_entry_id', $entry->id)->where('status', GatePriceVersion::ACCEPTED)
            ->orderByDesc('version')->first();
    }

    /**
     * The price first offered for a vehicle: its sauda's rate (null without a sauda). At that price the
     * lab's quality rate cut still applies on the bill; a negotiated (revised) price is final, without it.
     */
    public function offeredPricePaise(GateEntry $entry): ?int
    {
        $sauda = $entry->sauda_id !== null ? Sauda::query()->find($entry->sauda_id) : null;

        return $sauda?->rate_paise_per_maund;
    }

    /**
     * Proposes a price for a vehicle (a new version; earlier proposals not yet decided are superseded).
     * A price below the offered one needs the reason. Decided by someone else with purchase.approve.
     */
    public function proposePrice(GateEntry $entry, int $pricePaisePerMaund, ?string $reason): GatePriceVersion
    {
        $this->require('purchase.negotiate');

        return DB::transaction(function () use ($entry, $pricePaisePerMaund, $reason): GatePriceVersion {
            $entry = $this->lock($entry);
            if ($entry->direction !== 'inbound') {
                throw new BusinessRuleViolation('Prices are negotiated for inbound lots only.');
            }
            if ($this->settlementApproved($entry)) {
                throw new BusinessRuleViolation('The settlement is approved; the price can no longer change here.');
            }
            if ($pricePaisePerMaund <= 0) {
                throw new BusinessRuleViolation('The price must be greater than zero.');
            }
            $offered = $this->offeredPricePaise($entry);
            $reason = trim((string) $reason) === '' ? null : trim((string) $reason);
            $last = GatePriceVersion::query()->where('gate_entry_id', $entry->id)->max('version');
            if ($reason === null && ($last !== null || ($offered !== null && $pricePaisePerMaund < $offered))) {
                throw new BusinessRuleViolation('Give the reason for this price.');
            }
            GatePriceVersion::query()->where('gate_entry_id', $entry->id)->where('status', GatePriceVersion::PROPOSED)
                ->update(['status' => GatePriceVersion::SUPERSEDED]);

            return GatePriceVersion::query()->create([
                'gate_entry_id' => $entry->id, 'version' => (int) $last + 1, 'price_paise_per_maund' => $pricePaisePerMaund,
                'kind' => $last === null ? 'offered' : 'revised', 'reason' => $reason, 'status' => GatePriceVersion::PROPOSED,
                'proposed_by' => $this->actor->accountId(), 'proposed_at' => now(),
            ]);
        });
    }

    /** Accepts or rejects the latest proposed price. Never by the person who proposed it. */
    public function decidePrice(GateEntry $entry, bool $accept, ?string $note = null): GatePriceVersion
    {
        $this->require('purchase.approve');

        return DB::transaction(function () use ($entry, $accept, $note): GatePriceVersion {
            $entry = $this->lock($entry);
            $version = GatePriceVersion::query()->where('gate_entry_id', $entry->id)->where('status', GatePriceVersion::PROPOSED)
                ->orderByDesc('version')->lockForUpdate()->first() ?? throw new BusinessRuleViolation('No price is waiting for a decision.');
            if ($version->proposed_by !== null && $version->proposed_by === $this->actor->accountId() && ! SelfApproval::allowed($this->actor->account())) {
                throw new BusinessRuleViolation('A price is decided by someone other than the person who proposed it.');
            }
            if (! $accept && trim((string) $note) === '') {
                throw new BusinessRuleViolation('Give the reason for rejecting the price.');
            }
            $version->forceFill([
                'status' => $accept ? GatePriceVersion::ACCEPTED : GatePriceVersion::REJECTED,
                'decided_by' => $this->actor->accountId(), 'decided_at' => now(), 'decision_note' => $note,
            ])->save();

            return $version;
        });
    }

    /**
     * A lot the lab rejected (also beyond a grace limit) comes in after negotiation: needs a price
     * accepted after the rejection, the reason, and someone with purchase.approve. Until then it is
     * held apart: no weighing, no stock, no bill.
     */
    public function acceptRejectedLot(GateEntry $entry, string $reason): GateEvent
    {
        $this->require('purchase.approve');
        if (mb_strlen(trim($reason)) < 10) {
            throw new BusinessRuleViolation('Give the reason for accepting the rejected lot (at least 10 characters).');
        }

        return DB::transaction(function () use ($entry, $reason): GateEvent {
            $entry = $this->lock($entry);
            $rejection = GateEvent::query()->where('gate_entry_id', $entry->id)->whereIn('event', ['lab_accepted', 'lab_rejected'])
                ->newestFirst()->first();
            if ($rejection === null || $rejection->event !== 'lab_rejected') {
                throw new BusinessRuleViolation('Only a lot the lab rejected is accepted through negotiation.');
            }
            $price = $this->acceptedPrice($entry);
            if ($price === null || $price->decided_at === null || $price->decided_at->lt($rejection->recorded_at)) {
                throw new BusinessRuleViolation('First agree a price for the rejected lot: propose it and have it accepted.');
            }
            $test = LabTest::query()->where('gate_entry_id', $entry->id)->latestFirst()->firstOrFail();
            [$data] = $this->labDecisionData($entry, $test, $reason);
            $event = $this->appendEvent($entry, 'lab_accepted', $data + [
                'after_negotiation' => true, 'price_version_id' => $price->id, 'price_paise_per_maund' => $price->price_paise_per_maund,
                'rejection_note' => $rejection->data['note'] ?? null,
            ], now(), true);
            $this->project($entry);

            return $event;
        });
    }

    /** Is the settlement approved now (and not reopened since)? */
    public function settlementApproved(GateEntry $entry): bool
    {
        $count = fn (string $event) => GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', $event)->count();

        return $count('settlement_approved') > $count('settlement_reopened');
    }

    /**
     * Reopens an approved settlement, before the slip, with the reason (purchase.approve): the Caat and
     * the price can then be proposed and approved again. After the slip the stock and the purchase bill
     * exist; a correction then goes through the bill (cancellation with approval, or a claim).
     */
    public function reopenSettlement(GateEntry $entry, string $reason): GateEvent
    {
        $this->require('purchase.approve');
        if (mb_strlen(trim($reason)) < 10) {
            throw new BusinessRuleViolation('Give the reason for reopening the settlement (at least 10 characters).');
        }

        return DB::transaction(function () use ($entry, $reason): GateEvent {
            $entry = $this->lock($entry);
            if (! $this->settlementApproved($entry)) {
                throw new BusinessRuleViolation('There is no approved settlement to reopen.');
            }
            if ($this->hasEvent($entry, 'slip_issued')) {
                throw new BusinessRuleViolation('The slip is issued: correct it through the purchase bill (cancellation or claim), not here.');
            }
            $approved = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'settlement_approved')->newestFirst()->first();
            $event = $this->appendEvent($entry, 'settlement_reopened', ['reason' => trim($reason), 'proposal_id' => $approved?->data['proposal_id'] ?? null], now(), false);
            $this->project($entry);

            return $event;
        });
    }

    /**
     * A rejected lot kept at the mill while it is negotiated: recorded where it is held. It is not stock
     * (not owned): no weighing, no stock, no bill, until it is accepted after negotiation, or it leaves.
     */
    public function holdRejectedLot(GateEntry $entry, string $location, ?string $note = null): GateEvent
    {
        $this->require('gate.entry');

        return DB::transaction(function () use ($entry, $location, $note): GateEvent {
            $entry = $this->lock($entry);
            if (! $this->latestLabDecisionIsReject($entry)) {
                throw new BusinessRuleViolation('Only a lot the lab rejected is held apart (quarantine).');
            }
            if (trim($location) === '') {
                throw new BusinessRuleViolation('Say where the lot is held.');
            }
            $event = $this->appendEvent($entry, 'quarantined', ['location' => mb_substr(trim($location), 0, 120), 'note' => $note], now(), false);
            $this->project($entry);

            return $event;
        });
    }

    /**
     * The purchase officer's settlement after the empty weight: the two Caats and the price.
     *   Waste Caat       kg typed for unusable material
     *   Caat per Maund   kg per maund × net maunds (net kg ÷ 40)
     *   payable weight   net − (waste + per-maund Caat); this is also what stock receives
     * The price is the last accepted one (or, the first time, the offered price, which the approval
     * accepts). Approved by someone else with purchase.approve.
     */
    public function proposeSettlement(GateEntry $entry, string $wasteKg, string $perMaundRateKg, ?string $note = null): GateEvent
    {
        $this->require('purchase.negotiate');

        return DB::transaction(function () use ($entry, $wasteKg, $perMaundRateKg, $note): GateEvent {
            $entry = $this->lock($entry);
            if ($entry->direction !== 'inbound') {
                throw new BusinessRuleViolation('A settlement is for inbound lots.');
            }
            if ($this->settlementApproved($entry)) {
                throw new BusinessRuleViolation('The settlement is already approved.');
            }
            if (! $this->hasEvent($entry, 'lab_accepted') || $this->latestLabDecisionIsReject($entry)) {
                throw new BusinessRuleViolation('The lot must be accepted (by the lab or after negotiation) before its settlement.');
            }
            $this->project($entry);
            if ($entry->gross_kg === null || $entry->tare_kg === null || $entry->net_kg === null) {
                throw new BusinessRuleViolation('Weigh the loaded and the empty vehicle before the settlement.');
            }
            foreach (['Waste Caat' => $wasteKg, 'Caat per Maund' => $perMaundRateKg] as $label => $value) {
                if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', trim($value))) {
                    throw new BusinessRuleViolation("{$label} must be a number of kg, zero or more.");
                }
            }
            $net = Weight::kg((string) $entry->net_kg);
            $waste = Weight::kg(trim($wasteKg));
            $perMaund = Weight::kg(bcdiv(bcmul($net->kg, trim($perMaundRateKg), 6), '40', 3));
            $total = $waste->plus($perMaund);
            if ($total->compare($net) >= 0) {
                throw new BusinessRuleViolation('The total Caat ('.$total->kg.' kg) cannot reach the net weight ('.$net->kg.' kg).');
            }

            $price = $this->acceptedPrice($entry);
            if ($price === null) {
                // The price waiting for a decision, else the offered (sauda) price; the approval accepts it.
                $price = GatePriceVersion::query()->where('gate_entry_id', $entry->id)->where('status', GatePriceVersion::PROPOSED)->orderByDesc('version')->first();
                if ($price === null) {
                    $offered = $this->offeredPricePaise($entry)
                        ?? throw new BusinessRuleViolation('There is no sauda for this lot: propose its price first.');
                    $price = $this->proposePrice($entry, $offered, null);
                }
            }

            $event = $this->appendEvent($entry, 'settlement_proposed', [
                'net_kg' => $net->kg, 'waste_caat_kg' => $waste->kg, 'per_maund_rate_kg' => trim($perMaundRateKg),
                'per_maund_caat_kg' => $perMaund->kg, 'total_caat_kg' => $total->kg, 'payable_kg' => $net->minus($total)->kg,
                'price_version_id' => $price->id, 'price_version' => $price->version, 'price_paise_per_maund' => $price->price_paise_per_maund,
                'price_kind' => $price->kind, 'rate_cut_paise_per_maund' => $price->kind === 'offered' ? (int) ($entry->rate_cut_paise_per_maund ?? 0) : 0,
                'price_status' => $price->status, 'note' => trim((string) $note) === '' ? null : trim((string) $note),
            ], now(), false);

            return $event;
        });
    }

    /**
     * Approves the latest settlement proposal: never by the person who proposed it (nor by the one who
     * proposed its price). A price still waiting is accepted with it. After this the slip books stock
     * at the payable weight and the purchase bill at the settled price.
     */
    public function approveSettlement(GateEntry $entry, ?string $note = null): GateEvent
    {
        $this->require('purchase.approve');

        return DB::transaction(function () use ($entry, $note): GateEvent {
            $entry = $this->lock($entry);
            if ($this->settlementApproved($entry)) {
                throw new BusinessRuleViolation('The settlement is already approved.');
            }
            $proposal = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'settlement_proposed')
                ->newestFirst()->first() ?? throw new BusinessRuleViolation('No settlement has been proposed.');
            $reopenedProposals = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'settlement_reopened')->pluck('data')
                ->map(fn ($d) => $d['proposal_id'] ?? null)->filter()->all();
            if (in_array($proposal->id, $reopenedProposals, true)) {
                throw new BusinessRuleViolation('The settlement was reopened: propose it again.');
            }
            $me = $this->actor->accountId();
            $mainUser = SelfApproval::allowed($this->actor->account());
            if ($proposal->actor_login_account_id !== null && $proposal->actor_login_account_id === $me && ! $mainUser) {
                throw new BusinessRuleViolation('A settlement is approved by someone other than the person who proposed it.');
            }
            $this->project($entry);
            if ((string) $entry->net_kg !== (string) ($proposal->data['net_kg'] ?? '')) {
                throw new BusinessRuleViolation('The weights changed after the settlement was proposed; propose it again.');
            }
            $price = GatePriceVersion::query()->lockForUpdate()->findOrFail($proposal->data['price_version_id']);
            if ($price->status === GatePriceVersion::PROPOSED) {
                if ($price->proposed_by !== null && $price->proposed_by === $me && ! $mainUser) {
                    throw new BusinessRuleViolation('You proposed this price; someone else approves it.');
                }
                $price->forceFill(['status' => GatePriceVersion::ACCEPTED, 'decided_by' => $me, 'decided_at' => now(),
                    'decision_note' => 'Accepted with the settlement'])->save();
            } elseif ($price->status !== GatePriceVersion::ACCEPTED) {
                throw new BusinessRuleViolation('The price of this settlement is no longer valid; propose the settlement again.');
            }

            $event = $this->appendEvent($entry, 'settlement_approved', ['proposal_id' => $proposal->id, 'note' => $note]
                + (array) $proposal->data + ['price_status' => GatePriceVersion::ACCEPTED], now(), true);
            $this->project($entry);

            return $event;
        });
    }

    // ------------------------------------------------------------------ yard & exit

    /** The godown is where the slip will book the paddy receipt. */
    public function markUnloaded(GateEntry $entry, ?CarbonInterface $at = null, bool $strict = true, ?Godown $godown = null): GateEvent
    {
        $this->require('gate.entry');

        $event = $this->simpleEvent($entry, 'unloaded', $at, $strict, function (GateEntry $e): void {
            if ($e->direction === 'inbound' && ! $this->hasEvent($e, 'lab_accepted')) {
                throw new BusinessRuleViolation('Unload only after the lab has accepted the lot.');
            }
        }, $godown ? ['godown_id' => $godown->id, 'godown' => $godown->code] : [], function (GateEntry $e) use ($godown): void {
            if ($godown !== null && $godown->status !== 'active') {
                throw new BusinessRuleViolation("Godown {$godown->name} is not active.");
            }
            if ($godown !== null) {
                $e->godown_id = $godown->id;
            }
        });
        if ($entry->direction === 'inbound') {
            // The party hears that its vehicle was unloaded (once this is saved).
            app(PartyEventMessages::class)->unloaded($entry->fresh() ?? $entry);
        }

        return $event;
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

            $order = $entry->direction === 'outbound' && $entry->dispatch_order_id !== null
                ? DispatchOrder::query()->lockForUpdate()->find($entry->dispatch_order_id) : null;
            if ($order !== null) {
                $entry->flags = array_values(array_unique([...($entry->flags ?? []), ...$this->dispatchFlags($entry, $order)]));
            }

            $accepted = $this->hasEvent($entry, 'lab_accepted') && ! $this->latestLabDecisionIsReject($entry);
            $settlement = $this->settlementApproved($entry)
                ? GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'settlement_approved')->newestFirst()->first()
                : null;
            if ($entry->direction === 'inbound' && $accepted && $settlement === null) {
                throw new BusinessRuleViolation('Approve the settlement (Caat and price) before issuing the slip.');
            }
            $snapshot = [
                'gross_kg' => $entry->gross_kg, 'tare_kg' => $entry->tare_kg, 'net_kg' => $entry->net_kg,
                'deduction_kg' => $entry->deduction_kg, 'payable_kg' => $entry->payable_kg,
                'rate_cut_paise_per_maund' => $entry->rate_cut_paise_per_maund, 'lab_accepted' => $accepted,
                'flags' => $entry->flags ?? [],
            ] + ($settlement !== null ? ['settlement' => $settlement->data] : []);
            $event = $this->appendEvent($entry, 'slip_issued', $snapshot, now(), $strict);

            if ($entry->direction === 'inbound' && $accepted && $entry->sauda_id !== null && $entry->payable_kg !== null) {
                $sauda = Sauda::query()->find($entry->sauda_id);
                if ($sauda !== null && $sauda->isOpenForDelivery()) {
                    $this->saudas->recordDelivery($sauda, $entry, Weight::kg($entry->payable_kg));
                }
            }
            if ($entry->direction === 'inbound' && $accepted) {
                $this->bookReceipt($entry);
                $this->bills->draftFromSlip($entry);
            }
            if ($order !== null) {
                $this->dispatch($entry, $order);
            }
            $this->project($entry);
            // The party hears the final weights (once the slip is saved).
            app(PartyEventMessages::class)->weighed($entry);

            return $event;
        });
    }

    /** Outbound: the vehicle has been loaded from the dispatch order's godown. */
    public function markLoaded(GateEntry $entry, ?int $bagCount = null, ?CarbonInterface $at = null, bool $strict = true): GateEvent
    {
        $this->require('dispatch.manage');
        if ($entry->direction !== 'outbound' || $entry->dispatch_order_id === null) {
            throw new BusinessRuleViolation('Only a vehicle collecting a dispatch order is loaded.');
        }
        $order = DispatchOrder::query()->findOrFail($entry->dispatch_order_id);

        return $this->simpleEvent($entry, 'loaded', $at, $strict, function (GateEntry $e): void {
            if (! $this->hasEvent($e, 'tare_weighed')) {
                throw new BusinessRuleViolation('Weigh the empty vehicle (tare) before loading.');
            }
        }, ['godown_id' => $order->godown_id, 'bag_count' => $bagCount ?? $order->bag_count], function (GateEntry $e) use ($bagCount): void {
            if ($bagCount !== null) {
                $e->bag_count = $bagCount;
            }
        });
    }

    /**
     * Outbound: the document the vehicle leaves with. Needs the slip and the posted tax invoice,
     * so goods never move without an invoice.
     */
    public function issueGatePass(GateEntry $entry): GateEvent
    {
        $this->require('gatepass.issue');

        return DB::transaction(function () use ($entry): GateEvent {
            $entry = $this->lock($entry);
            if ($entry->direction !== 'outbound' || $entry->dispatch_order_id === null) {
                throw new BusinessRuleViolation('Gate passes are for dispatches.');
            }
            if (! $this->hasEvent($entry, 'slip_issued')) {
                throw new BusinessRuleViolation('Issue the weighment slip first.');
            }
            if ($this->hasEvent($entry, 'gate_pass_issued')) {
                throw new BusinessRuleViolation('A gate pass was already issued.');
            }
            $bill = SalesBill::query()->where('gate_entry_id', $entry->id)->first();
            if ($bill === null || $bill->status !== SalesBill::POSTED) {
                throw new BusinessRuleViolation('The invoice must be posted by accounts before the gate pass.');
            }
            $event = $this->appendEvent($entry, 'gate_pass_issued', [
                'number' => $this->numbers->next(DocumentNumbers::GATE_PASS),
                'invoice_number' => $bill->number, 'net_kg' => $entry->net_kg, 'bag_count' => $entry->bag_count,
            ], now(), true);
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
            if ($e->direction === 'outbound' && $e->dispatch_order_id !== null && ! $this->hasEvent($e, 'gate_pass_issued')) {
                throw new BusinessRuleViolation('A loaded vehicle leaves only with a gate pass.');
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
    public function recordDeviceEvent(GateEntry $entry, string $event, CarbonInterface $at, ?Godown $godown = null): GateEvent
    {
        return match ($event) {
            'unloaded' => $this->markUnloaded($entry, $at, false, $godown),
            'loaded' => $this->markLoaded($entry, null, $at, false),
            'exited' => $this->exit($entry, $at, false),
            default => throw new BusinessRuleViolation("Event {$event} cannot be sent by a device."),
        };
    }

    // ------------------------------------------------------------------ projection

    /** Recomputes status, effective weights, deductions and flags from the facts. */
    public function project(GateEntry $entry): GateEntry
    {
        $events = GateEvent::query()->where('gate_entry_id', $entry->id)->chronological()->get();
        // A corrected lab result clears the lab decisions taken before it (they stay in the timeline).
        $corrected = $events->where('event', 'lab_corrected')->last()?->seq;
        $events = $events->reject(fn (GateEvent $e) => $corrected !== null && in_array($e->event, ['lab_accepted', 'lab_rejected'], true) && $e->seq < $corrected)->values();
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
        $entry->rate_cut_paise_per_maund = null;
        if ($decision !== null && $decision->event === 'lab_accepted' && $entry->net_kg !== null && bccomp($entry->net_kg, '0', 3) > 0) {
            /** @var list<array<string, mixed>> $frozen rules as they were when the decision was taken */
            $frozen = (array) ($decision->data['rules'] ?? []);
            $rules = array_map(fn (array $r) => (new DeductionRule)->forceFill($r), $frozen);
            $result = $this->deductions->calculate($entry->net_kg, $entry->bag_count, $decision->data['measurements'] ?? [], $rules);
            $entry->deduction_kg = $result['deduction_kg'];
            $entry->payable_kg = Weight::kg($entry->net_kg)->minus(Weight::kg($result['deduction_kg']))->kg;
            $entry->rate_cut_paise_per_maund = $result['rate_cut_paise_per_maund'];
        }
        // An approved settlement decides: payable = net − (Waste Caat + Caat per Maund), and the rate cut it settled.
        $settled = $events->where('event', 'settlement_approved')->count() > $events->where('event', 'settlement_reopened')->count()
            ? $events->where('event', 'settlement_approved')->last() : null;
        if ($settled !== null && $entry->net_kg !== null) {
            $entry->deduction_kg = Weight::kg((string) $settled->data['total_caat_kg'])->kg;
            $entry->payable_kg = Weight::kg($entry->net_kg)->minus(Weight::kg($entry->deduction_kg))->kg;
            $entry->rate_cut_paise_per_maund = (int) ($settled->data['rate_cut_paise_per_maund'] ?? 0) ?: null;
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
        $flags = $flags->reject(fn ($f) => $f === 'held_quarantine');
        if (in_array('quarantined', $names, true) && $decision?->event === 'lab_rejected' && ! in_array('exited', $names, true)) {
            $flags->push('held_quarantine');
        }
        $entry->flags = $flags->unique()->values()->all();
        $entry->save();

        return $entry;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Stock receives the physical net weight (what is actually in the godown); quality
     * deductions only change what is paid for. Without a godown or product the receipt
     * cannot be booked, so the entry is flagged for the stores to resolve.
     */
    /**
     * Checks on a loaded vehicle against its dispatch order (flags, never silent corrections):
     * net outside the order quantity ± the sauda tolerance, or net far from bags × bag size.
     *
     * @return list<string>
     */
    private function dispatchFlags(GateEntry $entry, DispatchOrder $order): array
    {
        $flags = [];
        $net = Weight::kg((string) $entry->net_kg);
        $toleranceBp = (int) (Sauda::query()->whereKey($order->sauda_id)->value('tolerance_bp') ?? 0);
        $allowed = Weight::kg(bcdiv(bcmul($order->quantity_kg, (string) $toleranceBp, 3), '10000', 3));
        $diff = $net->minus(Weight::kg($order->quantity_kg));
        if (bccomp(ltrim($diff->kg, '-'), $allowed->kg, 3) > 0) {
            $flags[] = 'quantity_out_of_tolerance';
        }
        $bags = $entry->bag_count ?? $order->bag_count;
        if ($bags !== null && $order->bag_weight_kg !== null) {
            $expected = bcmul((string) $bags, $order->bag_weight_kg, 3);
            // Packed bags: allow 1 % for packing material and moisture.
            if (bccomp(ltrim(bcsub($net->kg, $expected, 3), '-'), bcdiv($expected, '100', 3), 3) > 0) {
                $flags[] = 'bag_weight_mismatch';
            }
        }

        return $flags;
    }

    /**
     * Outbound slip: stock leaves the order's godown (refused if short — the slip is then not issued),
     * the delivery is booked on the sales sauda and the tax invoice is drafted.
     */
    private function dispatch(GateEntry $entry, DispatchOrder $order): void
    {
        $product = $entry->product ?? throw new BusinessRuleViolation('The dispatch has no product.');
        $godown = Godown::query()->findOrFail($order->godown_id);
        $this->stock->post($product, $godown, Weight::zero()->minus(Weight::kg((string) $entry->net_kg)), StockEntry::DISPATCH, [
            'source_type' => 'gate_entry', 'source_id' => $entry->id, 'note' => "{$entry->number} · {$order->number}",
        ]);
        $sauda = Sauda::query()->find($order->sauda_id);
        if ($sauda !== null && $sauda->isOpenForDelivery()) {
            $this->saudas->recordDelivery($sauda, $entry, Weight::kg((string) $entry->net_kg));
        }
        $order->forceFill(['status' => DispatchOrder::DISPATCHED])->save();
        $this->salesBills->draftFromSlip($entry);
    }

    private function bookReceipt(GateEntry $entry): void
    {
        $godown = $entry->godown_id ? Godown::query()->find($entry->godown_id) : null;
        $product = $entry->product;
        if ($godown === null || $product === null || ! $product->is_stock_item) {
            $entry->flags = array_values(array_unique([...($entry->flags ?? []), 'no_godown']));
            $entry->save();

            return;
        }

        // Inbound stock receives the payable weight: the Caats are material that is not kept.
        $kg = $entry->direction === 'inbound' && $entry->payable_kg !== null ? $entry->payable_kg : $entry->net_kg;
        $this->stock->post($product, $godown, Weight::kg((string) $kg), StockEntry::RECEIPT, [
            'source_type' => 'gate_entry', 'source_id' => $entry->id, 'note' => $entry->number,
        ]);
    }

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
            'seq' => (int) GateEvent::query()->where('gate_entry_id', $entry->id)->max('seq') + 1,
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
        $query = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', $event);
        if (in_array($event, ['lab_accepted', 'lab_rejected'], true)) {
            // Lab decisions before a corrected result no longer count.
            $corrected = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'lab_corrected')->max('seq');
            $query->when($corrected !== null, fn ($q) => $q->where('seq', '>', $corrected));
        }

        return $query->exists();
    }

    private function lastEventName(GateEntry $entry): ?string
    {
        return GateEvent::query()->where('gate_entry_id', $entry->id)->whereIn('event', ['exited', 'cancelled'])->value('event');
    }

    private function latestLabDecisionIsReject(GateEntry $entry): bool
    {
        return GateEvent::query()->where('gate_entry_id', $entry->id)->whereIn('event', ['lab_accepted', 'lab_rejected'])
            ->newestFirst()->value('event') === 'lab_rejected';
    }

    /** @param  callable(GateEntry): void  $guard */
    /**
     * @param  callable(GateEntry): void  $guard  flow rule, enforced only when strict
     * @param  array<string, mixed>  $data
     * @param  (callable(GateEntry): void)|null  $apply  always runs (validation + changes before projection)
     */
    private function simpleEvent(GateEntry $entry, string $event, ?CarbonInterface $at, bool $strict, callable $guard, array $data = [], ?callable $apply = null): GateEvent
    {
        return DB::transaction(function () use ($entry, $event, $at, $strict, $guard, $data, $apply): GateEvent {
            $entry = $this->lock($entry);
            if ($strict) {
                $guard($entry);
            }
            if ($apply !== null) {
                $apply($entry);
            }
            $gateEvent = $this->appendEvent($entry, $event, $data, $at ?? now(), $strict);
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

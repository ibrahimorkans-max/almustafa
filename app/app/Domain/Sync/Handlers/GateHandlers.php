<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Gate\GateDevices;
use App\Domain\Gate\GateService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncEvent;
use App\Domain\Sync\SyncOutcome;
use App\Models\Gate\GateDeviceEvent;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabTest;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Vehicle;
use App\Support\Values\PakistanIds;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Append-only gate events from devices (gate tablet, lab tablet, weighbridge agent).
 * Facts never overwrite each other, so these handlers have no conflict branch: a fact is
 * either recorded, rejected with a reason, or — if it refers to a vehicle another device has
 * not uploaded yet — answered "retry" so the device sends it again later.
 */
final class GateHandlers
{
    /** @return list<SyncHandler> */
    public static function all(GateService $gate): array
    {
        return [
            self::make('gate_entry.arrived', ['gate.entry'], function (SyncEvent $e) use ($gate): SyncOutcome {
                if ($e->recordId === null) {
                    return new SyncOutcome(SyncOutcome::REJECTED, reason: 'record_id_required');
                }
                if (GateEntry::query()->whereKey($e->recordId)->exists()) {
                    return new SyncOutcome(SyncOutcome::APPLIED, ['note' => 'already recorded']);
                }
                $v = Validator::make($e->payload, [
                    'no_plate' => ['sometimes', 'boolean'],
                    'registration_no' => ['required_unless:no_plate,true', 'nullable', 'string', 'max:20', fn (string $a, mixed $val, Closure $fail) => PakistanIds::isValidVehicleNumber(PakistanIds::normalizeVehicleNumber((string) $val)) ? null : $fail('Invalid registration number.')],
                    'vehicle_type' => ['nullable', Rule::in(Vehicle::TYPES)],
                    'vehicle_description' => ['nullable', 'required_if:no_plate,true', 'string', 'max:160'],
                    'direction' => ['sometimes', Rule::in(['inbound', 'outbound'])],
                    'purpose' => ['sometimes', Rule::in(['paddy_purchase', 'dispatch', 'other'])],
                    'party_id' => ['nullable', 'uuid'],
                    'sauda_id' => ['nullable', 'uuid'],
                    'product_id' => ['nullable', 'uuid'],
                    'driver_id' => ['nullable', 'uuid'],
                    'driver_name' => ['nullable', 'string', 'max:120'],
                    'bag_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
                ]);
                if ($v->fails()) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
                }
                $entry = $gate->arrive($v->validated(), $e->recordId, $e->occurredAt);

                return new SyncOutcome(SyncOutcome::APPLIED, ['number' => $entry->number, 'flags' => $entry->flags]);
            }),

            self::make('weighment.captured', ['weighment.capture'], function (SyncEvent $e) use ($gate): SyncOutcome {
                if ($e->recordId !== null && Weighment::query()->whereKey($e->recordId)->exists()) {
                    return new SyncOutcome(SyncOutcome::APPLIED, ['note' => 'already recorded']);
                }
                $v = Validator::make($e->payload, [
                    'gate_entry_id' => ['required', 'uuid'],
                    'kind' => ['required', Rule::in([Weighment::GROSS, Weighment::TARE])],
                    'weight_kg' => ['required', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
                    'scale_id' => ['nullable', 'uuid'],
                    'readings' => ['nullable', 'array'],
                    'readings.samples' => ['nullable', 'array', 'max:200'],
                    'photo_sha256' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
                ]);
                if ($v->fails()) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
                }
                $entry = GateEntry::query()->find($e->payload['gate_entry_id']);
                if ($entry === null) {
                    return new SyncOutcome(SyncOutcome::RETRY, reason: 'gate_entry_not_yet_synced');
                }
                $scale = isset($e->payload['scale_id']) ? Scale::query()->find($e->payload['scale_id']) : null;
                $w = $gate->recordWeighment($entry, (string) $e->payload['kind'], (string) $e->payload['weight_kg'], $scale,
                    $e->payload['readings'] ?? null, $e->payload['photo_sha256'] ?? null, $e->occurredAt, strict: false, id: $e->recordId);

                return new SyncOutcome(SyncOutcome::APPLIED, ['weighment_id' => $w->id, 'flags' => $w->flags]);
            }),

            self::make('lab_test.recorded', ['lab.test'], function (SyncEvent $e) use ($gate): SyncOutcome {
                if ($e->recordId !== null && LabTest::query()->whereKey($e->recordId)->exists()) {
                    return new SyncOutcome(SyncOutcome::APPLIED, ['note' => 'already recorded']);
                }
                $bp = ['nullable', 'integer', 'min:0', 'max:10000'];
                $v = Validator::make($e->payload, [
                    'gate_entry_id' => ['required', 'uuid'],
                    'sample_code' => ['nullable', 'string', 'max:40'],
                    'moisture_bp' => $bp, 'foreign_matter_bp' => $bp, 'broken_bp' => $bp,
                    'damaged_bp' => $bp, 'immature_bp' => $bp, 'chalky_bp' => $bp,
                    // The configured lab tests (LabStandards): code => basis points.
                    'results' => ['nullable', 'array', 'max:30'], 'results.*' => ['integer', 'min:0', 'max:10000'],
                    'grade' => ['nullable', 'string', 'max:20'],
                    'remarks' => ['nullable', 'string', 'max:1000'],
                ]);
                if ($v->fails()) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
                }
                $entry = GateEntry::query()->find($e->payload['gate_entry_id']);
                if ($entry === null) {
                    return new SyncOutcome(SyncOutcome::RETRY, reason: 'gate_entry_not_yet_synced');
                }
                $test = $gate->recordLabTest($entry, $v->validated(), $e->occurredAt, $e->recordId);

                return new SyncOutcome(SyncOutcome::APPLIED, ['lab_test_id' => $test->id]);
            }),

            self::make('gate_entry.lab_decided', ['lab.decide'], function (SyncEvent $e) use ($gate): SyncOutcome {
                $v = Validator::make($e->payload, [
                    'gate_entry_id' => ['required', 'uuid'],
                    'accept' => ['required', 'boolean'],
                    'note' => ['nullable', 'string', 'max:1000'],
                ]);
                if ($v->fails()) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
                }
                $entry = GateEntry::query()->find($e->payload['gate_entry_id']);
                if ($entry === null || ! LabTest::query()->where('gate_entry_id', $entry->id)->exists()) {
                    return new SyncOutcome(SyncOutcome::RETRY, reason: 'lab_test_not_yet_synced');
                }
                $gate->decideLab($entry, (bool) $e->payload['accept'], $e->payload['note'] ?? null, $e->occurredAt, strict: false);

                return new SyncOutcome(SyncOutcome::APPLIED);
            }),

            self::make('gate_entry.event', ['gate.entry'], function (SyncEvent $e) use ($gate): SyncOutcome {
                $v = Validator::make($e->payload, [
                    'gate_entry_id' => ['required', 'uuid'],
                    'event' => ['required', Rule::in(['unloaded', 'loaded', 'exited'])],
                    'godown_id' => ['nullable', 'uuid'],
                ]);
                if ($v->fails()) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
                }
                $entry = GateEntry::query()->find($e->payload['gate_entry_id']);
                if ($entry === null) {
                    return new SyncOutcome(SyncOutcome::RETRY, reason: 'gate_entry_not_yet_synced');
                }
                $godown = null;
                if (isset($e->payload['godown_id'])) {
                    $godown = Godown::query()->find($e->payload['godown_id'])
                        ?? throw new BusinessRuleViolation('Unknown godown.');
                }
                $event = $gate->recordDeviceEvent($entry, (string) $e->payload['event'], $e->occurredAt, $godown);

                return new SyncOutcome(SyncOutcome::APPLIED, ['anomaly' => $event->anomaly]);
            }),

            self::make('gate_entry.plate_read', ['gate.entry'], function (SyncEvent $e): SyncOutcome {
                if ($e->recordId !== null && GateDeviceEvent::query()->whereKey($e->recordId)->exists()) {
                    return new SyncOutcome(SyncOutcome::APPLIED, ['note' => 'already recorded']);
                }
                $v = Validator::make($e->payload, [
                    'plate' => ['required', 'string', 'max:20'],
                    'confidence_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
                    'photo_sha256' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
                    'gate_entry_id' => ['nullable', 'uuid'],
                ]);
                if ($v->fails()) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
                }
                $entry = isset($e->payload['gate_entry_id']) ? GateEntry::query()->find($e->payload['gate_entry_id']) : null;
                if (isset($e->payload['gate_entry_id']) && $entry === null) {
                    return new SyncOutcome(SyncOutcome::RETRY, reason: 'gate_entry_not_yet_synced');
                }
                $r = app(GateDevices::class)->plateRead((string) $e->payload['plate'], $e->payload['confidence_bp'] ?? null,
                    $e->payload['photo_sha256'] ?? null, $entry, $e->occurredAt, $e->recordId);

                return new SyncOutcome(SyncOutcome::APPLIED, ['gate_entry_id' => $r['gate_entry']?->id, 'mismatch' => $r['mismatch']]);
            }),

            self::make('barrier.manual_open', ['gate.entry'], function (SyncEvent $e): SyncOutcome {
                if ($e->recordId !== null && GateDeviceEvent::query()->whereKey($e->recordId)->exists()) {
                    return new SyncOutcome(SyncOutcome::APPLIED, ['note' => 'already recorded']);
                }
                $v = Validator::make($e->payload, [
                    'side' => ['required', Rule::in(['in', 'out'])],
                    'reason' => ['required', 'string', 'min:5', 'max:255'],
                    'gate_entry_id' => ['nullable', 'uuid'],
                ]);
                if ($v->fails()) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
                }
                $entry = isset($e->payload['gate_entry_id']) ? GateEntry::query()->find($e->payload['gate_entry_id']) : null;
                if (isset($e->payload['gate_entry_id']) && $entry === null) {
                    return new SyncOutcome(SyncOutcome::RETRY, reason: 'gate_entry_not_yet_synced');
                }
                $event = app(GateDevices::class)->manualOpen($entry, (string) $e->payload['side'], (string) $e->payload['reason'], $e->occurredAt, $e->recordId);

                return new SyncOutcome(SyncOutcome::APPLIED, ['event_id' => $event->id]);
            }),
        ];
    }

    /**
     * @param  list<string>  $permissions  any of
     * @param  Closure(SyncEvent): SyncOutcome  $apply
     */
    private static function make(string $type, array $permissions, Closure $apply): SyncHandler
    {
        return new class($type, $permissions, $apply) implements SyncHandler
        {
            /** @param  list<string>  $permissions */
            public function __construct(private string $type, private array $permissions, private Closure $apply) {}

            public function type(): string
            {
                return $this->type;
            }

            public function canWrite(LoginAccount $account): bool
            {
                foreach ($this->permissions as $p) {
                    if ($account->hasPermission($p)) {
                        return true;
                    }
                }

                return false;
            }

            public function requiresOnlineConfirmation(array $payload): bool
            {
                return false;
            }

            public function apply(SyncEvent $event, LoginAccount $account): SyncOutcome
            {
                try {
                    return ($this->apply)($event);
                } catch (BusinessRuleViolation $e) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['message' => $e->getMessage()], 'business_rule');
                }
            }
        };
    }
}

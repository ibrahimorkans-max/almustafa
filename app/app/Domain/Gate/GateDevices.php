<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateDeviceEvent;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Support\Values\PakistanIds;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Number-plate camera and boom barrier rules. The server decides; the agent only opens the
 * barrier on an "open" answer and keeps it closed when the server cannot be reached. A guard's
 * manual open always needs a reason and is flagged on the vehicle.
 */
final class GateDevices
{
    public const OPEN_STATUSES = ['arrived', 'gross_weighed', 'tare_weighed', 'lab_accepted', 'lab_rejected', 'unloaded', 'loaded', 'slip_issued', 'gate_pass_issued'];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Records a plate read. Matches it to the given entry or to the open entry with that registration;
     * a difference between the typed registration and the plate read is flagged, never corrected.
     *
     * @return array{event: GateDeviceEvent, gate_entry: GateEntry|null, mismatch: bool}
     */
    public function plateRead(string $plate, ?int $confidenceBp, ?string $photoSha256, ?GateEntry $entry, CarbonInterface $at, ?string $id = null): array
    {
        $this->require();
        $normalized = PakistanIds::normalizeVehicleNumber($plate);

        return DB::transaction(function () use ($normalized, $confidenceBp, $photoSha256, $entry, $at, $id): array {
            $entry ??= $this->openEntryFor($normalized);
            $mismatch = $entry !== null && $entry->registration_no !== $normalized;
            if ($mismatch) {
                $this->flag($entry, 'plate_mismatch');
            }
            $event = $this->log(GateDeviceEvent::PLATE_READ, $entry, null, $at, [
                'plate' => $normalized, 'confidence_bp' => $confidenceBp, 'photo_sha256' => $photoSha256,
                'data' => ['matched' => $entry !== null, 'mismatch' => $mismatch, 'typed' => $entry?->registration_no],
            ], $id);

            return ['event' => $event, 'gate_entry' => $entry, 'mismatch' => $mismatch];
        });
    }

    /**
     * May the barrier open for this vehicle? Logged either way.
     *
     * @return array{open: bool, reason: string, gate_entry_id: string|null}
     */
    public function barrier(?GateEntry $entry, string $side, CarbonInterface $at): array
    {
        $this->require();
        if (! in_array($side, ['in', 'out'], true)) {
            throw new BusinessRuleViolation('Side must be in or out.');
        }
        [$open, $reason] = $entry === null ? [false, 'no_matching_vehicle'] : $this->rule($entry, $side);
        $this->log($open ? GateDeviceEvent::BARRIER_OPENED : GateDeviceEvent::BARRIER_REFUSED, $entry, $side, $at, ['reason' => $reason]);

        return ['open' => $open, 'reason' => $reason, 'gate_entry_id' => $entry?->id];
    }

    /** A guard opened the barrier by hand (power cut, server down, emergency). */
    public function manualOpen(?GateEntry $entry, string $side, string $reason, CarbonInterface $at, ?string $id = null): GateDeviceEvent
    {
        $this->require();
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('A manual barrier open needs a reason.');
        }

        return DB::transaction(function () use ($entry, $side, $reason, $at, $id): GateDeviceEvent {
            if ($entry !== null) {
                $this->flag($entry, 'barrier_manual_open');
            }
            $event = $this->log(GateDeviceEvent::BARRIER_MANUAL, $entry, $side, $at, ['reason' => mb_substr($reason, 0, 255)], $id);
            $this->audit->record('gate.barrier.manual_open', $event, null, ['side' => $side, 'reason' => $reason, 'gate_entry' => $entry?->number]);

            return $event;
        });
    }

    public function openEntryFor(string $registration): ?GateEntry
    {
        return GateEntry::query()->where('registration_no', $registration)->whereIn('status', self::OPEN_STATUSES)
            ->orderByDesc('arrived_at')->first();
    }

    /** @return array{0: bool, 1: string} */
    private function rule(GateEntry $entry, string $side): array
    {
        $flags = $entry->flags ?? [];
        $events = GateEvent::query()->where('gate_entry_id', $entry->id)->pluck('event')->all();
        $has = fn (string $e) => in_array($e, $events, true);

        if (in_array($entry->status, ['exited', 'cancelled'], true)) {
            return [false, "vehicle_{$entry->status}"];
        }
        if ($side === 'in') {
            if (in_array('blocked_vehicle', $flags, true)) {
                return [false, 'blocked_vehicle'];
            }
            if (in_array('dispatch_order_not_open', $flags, true)) {
                return [false, 'dispatch_order_not_open'];
            }

            return $entry->status === 'arrived' ? [true, 'arrival_registered'] : [false, 'already_inside'];
        }
        if ($entry->direction === 'outbound' && $entry->dispatch_order_id !== null) {
            return $has('gate_pass_issued') ? [true, 'gate_pass_issued'] : [false, 'no_gate_pass'];
        }
        if ($has('slip_issued') || $has('lab_rejected')) {
            return [true, $has('slip_issued') ? 'slip_issued' : 'lab_rejected'];
        }

        return [false, 'no_slip'];
    }

    private function flag(GateEntry $entry, string $flag): void
    {
        $entry = GateEntry::query()->lockForUpdate()->findOrFail($entry->id);
        $entry->flags = array_values(array_unique([...($entry->flags ?? []), $flag]));
        $entry->save();
    }

    /** @param  array<string, mixed>  $fields */
    private function log(string $kind, ?GateEntry $entry, ?string $side, CarbonInterface $at, array $fields, ?string $id = null): GateDeviceEvent
    {
        $event = new GateDeviceEvent($fields + [
            'gate_entry_id' => $entry?->id, 'device_id' => $this->actor->deviceId(), 'kind' => $kind, 'side' => $side,
            'actor_login_account_id' => $this->actor->accountId(), 'occurred_at' => $at, 'created_at' => now(),
        ]);
        if ($id !== null) {
            $event->id = $id;
        }
        $event->save();

        return $event;
    }

    private function require(): void
    {
        if (! ($this->actor->account()?->hasPermission('gate.entry') ?? false)) {
            throw new BusinessRuleViolation("You need 'gate.entry' for this.");
        }
    }
}

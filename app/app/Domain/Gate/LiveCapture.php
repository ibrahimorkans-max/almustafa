<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\Masters\Godown;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recording a weight from the Live weight screen: the operator picks the vehicle on the platform (the
 * list follows the token order: first come, first served) and presses "Loaded weight" or "Empty weight"
 * once the reading is stable. The weight is the gate agent's latest reading; the last readings go with
 * it as the stability evidence, the server checks them like the agent's own capture, and the weighment
 * is flagged `live_screen` (and `no_photo`) so the weight check sees how it was taken.
 * An inbound lot is marked unloaded (into the godown chosen there) just before its empty weight.
 */
final class LiveCapture
{
    /** Statuses in which a vehicle waits for one of its weights. */
    private const WAITING = ['arrived', 'lab_accepted', 'gross_weighed', 'unloaded', 'tare_weighed', 'loaded'];

    public function __construct(
        private readonly LiveWeight $live,
        private readonly GateService $gate,
    ) {}

    /**
     * Vehicles inside the mill that wait for a weight, in token order.
     *
     * @return Collection<int, array{id: string, label: string, kind: string|null, token: string|null, plate: string}>
     */
    public function waiting(): Collection
    {
        // Every open token, in token order (the continuous serial; older tokens by their day's number). The newest
        // 150 are taken, so old unfinished tokens can never push today's vehicles off the list.
        return GateEntry::query()->with('party')
            ->whereIn('status', self::WAITING)
            ->orderByDesc('arrived_at')->limit(150)->get()
            ->sortBy([
                fn (GateEntry $a, GateEntry $b) => ($a->token_serial === null) <=> ($b->token_serial === null),
                fn (GateEntry $a, GateEntry $b) => (int) $a->token_serial <=> (int) $b->token_serial,
                fn (GateEntry $a, GateEntry $b) => (string) $a->token_date?->format('Y-m-d') <=> (string) $b->token_date?->format('Y-m-d'),
                fn (GateEntry $a, GateEntry $b) => (int) $a->token_no <=> (int) $b->token_no,
                fn (GateEntry $a, GateEntry $b) => (string) $a->arrived_at?->format('Y-m-d H:i:s') <=> (string) $b->arrived_at?->format('Y-m-d H:i:s'),
            ])
            ->map(function (GateEntry $e): ?array {
                $steps = self::steps($e);
                if (! $steps['gross']['allowed'] && ! $steps['tare']['allowed'] && $steps['gross']['done'] && $steps['tare']['done']) {
                    return null;
                }
                // A paddy lot still at the lab is listed too (it keeps its place in the queue), marked as such.
                $atLab = $e->direction === 'inbound' && $e->purpose !== 'other' && $e->status === 'arrived';

                return [
                    'id' => $e->id,
                    'label' => trim(($e->token_no !== null || $e->token_serial !== null ? 'Token '.$e->tokenLabel().' · ' : '').($e->jack_type !== null ? $e->jackLabel().' · ' : '').($e->no_plate ? 'No plate ('.$e->vehicle_description.')' : $e->registration_no)
                        .($e->partyLabel() !== null ? ' · '.$e->partyLabel() : '').($atLab ? ' · at the lab' : '')),
                    'kind' => self::nextKind($e),
                    'token' => $e->token_serial !== null || $e->token_no !== null ? $e->tokenLabel() : null,
                    'plate' => $e->no_plate ? '' : $e->registration_no,
                ];
            })
            ->filter()->values();
    }

    /** gross / tare / null: what this entry needs next on the weighbridge (inbound: only after the lab accepted the lot). */
    public static function nextKind(GateEntry $entry): ?string
    {
        if ($entry->purpose === 'other') {
            // Simple weighing: no lab, no unloading or loading step.
            return match ([$entry->direction, $entry->status]) {
                ['inbound', 'arrived'], ['outbound', 'tare_weighed'] => Weighment::GROSS,
                ['inbound', 'gross_weighed'], ['outbound', 'arrived'] => Weighment::TARE,
                default => null,
            };
        }

        return match ([$entry->direction, $entry->status]) {
            ['inbound', 'lab_accepted'], ['outbound', 'loaded'] => Weighment::GROSS,
            ['inbound', 'unloaded'], ['inbound', 'gross_weighed'], ['outbound', 'arrived'] => Weighment::TARE,
            default => null,
        };
    }

    /**
     * For each weight: whether it is already taken, whether it can be recorded now, and if not, why.
     * `unload` is true when an inbound lot must be marked unloaded (choose the godown) before its empty weight.
     *
     * @return array{gross: array{done: bool, allowed: bool, why: string, kg: string|null, manual: bool}, tare: array{done: bool, allowed: bool, why: string, kg: string|null, manual: bool}, unload: bool, net: string|null}
     */
    public static function steps(GateEntry $entry): array
    {
        $gross = ManualWeight::current($entry, Weighment::GROSS);
        $tare = ManualWeight::current($entry, Weighment::TARE);
        $next = self::nextKind($entry);
        $inbound = $entry->direction === 'inbound';
        $why = function (string $kind) use ($entry, $inbound, $next): string {
            if ($next === $kind) {
                return '';
            }

            return match (true) {
                $inbound && $kind === Weighment::GROSS && in_array($entry->status, ['arrived'], true) => 'After the lab accepts the lot.',
                $inbound && $kind === Weighment::TARE && in_array($entry->status, ['arrived', 'lab_accepted'], true) => 'After the loaded weight and unloading.',
                ! $inbound && $kind === Weighment::GROSS && in_array($entry->status, ['arrived', 'tare_weighed'], true) => 'After the empty weight and loading (mark it loaded on the vehicle page).',
                ! $inbound && $kind === Weighment::TARE => 'Already taken.',
                default => 'Already taken.',
            };
        };

        return [
            'gross' => ['done' => $gross !== null, 'allowed' => $next === Weighment::GROSS, 'why' => $why(Weighment::GROSS),
                'kg' => $gross?->weight_kg, 'manual' => $gross?->source === Weighment::SOURCE_MANUAL],
            'tare' => ['done' => $tare !== null, 'allowed' => $next === Weighment::TARE, 'why' => $why(Weighment::TARE),
                'kg' => $tare?->weight_kg, 'manual' => $tare?->source === Weighment::SOURCE_MANUAL],
            'unload' => $inbound && $entry->purpose !== 'other' && $entry->status === 'gross_weighed',
            'net' => $entry->net_kg,
        ];
    }

    /**
     * Records the weight from the live reading. `kind` defaults to what the vehicle needs next; an
     * inbound lot still on the vehicle is first marked unloaded into `godown`.
     */
    public function record(GateEntry $entry, Scale $scale, ?string $kind = null, ?Godown $godown = null): Weighment
    {
        $kind ??= self::nextKind($entry);
        if ($kind === null || self::nextKind($entry) !== $kind) {
            $label = $kind === Weighment::TARE ? 'empty weight' : 'loaded weight';
            $why = $kind === null ? '' : self::steps($entry)[$kind]['why'];
            throw new BusinessRuleViolation(trim(($kind === null ? "Vehicle {$entry->registration_no} does not need a weight now (status: {$entry->status})."
                : "The {$label} of {$entry->registration_no} cannot be recorded now.").' '.$why));
        }
        if (self::steps($entry)['unload'] && $kind === Weighment::TARE) {
            if ($godown === null) {
                throw new BusinessRuleViolation('Choose the godown it was unloaded into, then record the empty weight.');
            }
        }
        $reading = $this->live->stableReading($scale);

        // Unloading (inbound, before the empty weight) and the weight succeed or fail together.
        return DB::transaction(function () use ($entry, $kind, $godown, $scale, $reading): Weighment {
            if (self::steps($entry)['unload'] && $kind === Weighment::TARE && $godown !== null) {
                $this->gate->markUnloaded($entry, godown: $godown);
                $entry->refresh();
            }

            return $this->gate->recordWeighment(
                $entry, $kind, $reading['kg'], $scale,
                ['samples' => $reading['samples'], 'window_ms' => $reading['window_ms'], 'via' => 'live_screen', 'agent_device_id' => $reading['device_id']],
                null,
                extraFlags: ['live_screen'],
            );
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use Illuminate\Support\Collection;

/**
 * Recording a weight from the Live weight screen: the operator picks the vehicle on the platform and
 * presses Record once the reading is stable. The weight is the gate agent's latest reading; the last
 * readings go with it as the stability evidence, the server checks them like the agent's own capture,
 * and the weighment is flagged `live_screen` (and `no_photo`) so the weight check sees how it was taken.
 */
final class LiveCapture
{
    public function __construct(
        private readonly LiveWeight $live,
        private readonly GateService $gate,
    ) {}

    /**
     * Vehicles inside the mill that wait for a weight, with the weight they need next.
     *
     * @return Collection<int, array{id: string, label: string, kind: string}>
     */
    public function waiting(): Collection
    {
        return GateEntry::query()->with('party')
            ->whereIn('status', ['arrived', 'unloaded', 'loaded'])
            ->orderBy('arrived_at')->limit(50)->get()
            ->map(function (GateEntry $e): ?array {
                $kind = self::nextKind($e);

                return $kind === null ? null : [
                    'id' => $e->id,
                    'label' => trim($e->registration_no.' · '.($e->number ?? '').($e->party !== null ? ' · '.$e->party->name : '')),
                    'kind' => $kind,
                ];
            })
            ->filter()->values();
    }

    /** gross / tare / null: what this entry needs next on the weighbridge. */
    public static function nextKind(GateEntry $entry): ?string
    {
        return match ([$entry->direction, $entry->status]) {
            ['inbound', 'arrived'], ['outbound', 'loaded'] => Weighment::GROSS,
            ['inbound', 'unloaded'], ['outbound', 'arrived'] => Weighment::TARE,
            default => null,
        };
    }

    public function record(GateEntry $entry, Scale $scale): Weighment
    {
        $kind = self::nextKind($entry);
        if ($kind === null) {
            throw new BusinessRuleViolation("Vehicle {$entry->registration_no} does not need a weight now (status: {$entry->status}).");
        }
        $reading = $this->live->stableReading($scale);

        return $this->gate->recordWeighment(
            $entry, $kind, $reading['kg'], $scale,
            ['samples' => $reading['samples'], 'window_ms' => $reading['window_ms'], 'via' => 'live_screen', 'agent_device_id' => $reading['device_id']],
            null,
            extraFlags: ['live_screen'],
        );
    }
}

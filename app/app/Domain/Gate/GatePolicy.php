<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Settings\SettingsService;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;

/**
 * The mill's weighing policy for paddy (Gate → Lab tests → Weighing policy):
 *
 *  on   (default) "weigh first": a vehicle is weighed loaded as soon as it arrives, without waiting for the lab.
 *       The lab result is added on its own page (Gate → Lab tests) before or after that weight; the lot is unloaded
 *       (and so weighed empty) only after the lab accepted it, so rejected paddy never goes into a godown.
 *       Flow: arrived → loaded weight → lab accepted → unloaded → empty weight → slip → exit.
 *  off  "lab first": the lab accepts the lot before it is weighed (GateEntry::INBOUND_FLOW).
 *
 * Read once per request for each mill.
 */
final class GatePolicy
{
    public const WEIGH_BEFORE_LAB = 'gate.weigh_before_lab';

    /** An inbound paddy lot when it is weighed before the lab. */
    public const WEIGH_FIRST_FLOW = ['arrived', 'gross_weighed', 'lab_accepted', 'unloaded', 'tare_weighed', 'settlement_approved', 'slip_issued', 'exited'];

    /** @var array<string, bool> */
    private array $cache = [];

    public static function weighBeforeLab(?string $entityId): bool
    {
        $policy = app(self::class);
        $key = $entityId ?? '';

        return $policy->cache[$key] ??= app(SettingsService::class)->get(self::WEIGH_BEFORE_LAB, $entityId) !== 'off';
    }

    /** This vehicle is weighed before its lab test (modes 1 and 3). */
    public static function weighsFirst(GateEntry $entry): bool
    {
        return WeighModes::weighsFirst(WeighModes::of($entry));
    }

    /** Sets the policy of a mill. */
    public static function set(bool $weighFirst, string $entityId): void
    {
        app(SettingsService::class)->put(self::WEIGH_BEFORE_LAB, $weighFirst ? 'on' : 'off', $entityId);
        app(self::class)->cache = [];
    }

    /** Whether the lab result of a paddy lot can still be recorded or decided: before its loaded weight (lab first), else before unloading. */
    public static function labOpen(GateEntry $entry): bool
    {
        $mode = WeighModes::of($entry);
        if ($entry->direction === 'inbound' && ! WeighModes::usesLab($mode)) {
            return false;   // weighed without a lab test (mode 2)
        }
        $event = WeighModes::weighsFirst($mode) ? 'unloaded' : 'gross_weighed';

        return ! GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', $event)->exists();
    }
}

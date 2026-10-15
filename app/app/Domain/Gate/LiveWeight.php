<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\Scale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The weighbridge reading as it changes, for the screens. The gate agent on the weighbridge PC sends
 * the indicator's value about once a second; it is kept in the cache only and goes stale after a few
 * seconds, so a dead agent or cable shows as "no signal". The last readings are kept too: recording a
 * weight from the Live weight screen (LiveCapture) takes them as its stability evidence.
 */
final class LiveWeight
{
    /** Readings kept per weighbridge for the evidence of a capture from the screen. */
    private const KEEP = 12;

    public function __construct(
        private readonly ActorContext $actor,
    ) {}

    /** @param  array{scale_id: string, kg: string|int|float, stable: bool, at?: string|null}  $reading */
    public function record(array $reading): void
    {
        if (! ($this->actor->account()?->hasPermission('weighment.capture') ?? false)) {
            throw new BusinessRuleViolation('Sending weights needs weighment.capture (the gate agent login).');
        }
        $scale = Scale::query()->find($reading['scale_id']);
        if ($scale === null || $scale->status !== 'active') {
            throw new BusinessRuleViolation('Unknown or inactive weighbridge.');
        }
        $kg = round((float) $reading['kg'], 1);
        if (abs($kg) > (float) $scale->capacity_kg * 1.2) {
            throw new BusinessRuleViolation('The reading is outside the weighbridge range.');
        }
        $now = now();
        Cache::put($this->key($scale), [
            'kg' => $kg, 'stable' => (bool) $reading['stable'], 'received_at' => $now->toIso8601String(),
            'device_id' => $this->actor->deviceId(),
        ], now()->addMinutes(10));

        $samples = Cache::get($this->samplesKey($scale), []);
        $samples[] = ['kg' => $kg, 'ms' => (int) $now->getTimestampMs()];
        Cache::put($this->samplesKey($scale), array_slice($samples, -self::KEEP), now()->addMinutes(10));
    }

    /**
     * @return array{scale_id: string, code: string, name: string, kg: float|null, stable: bool, live: bool, age_seconds: int|null}
     */
    public function current(Scale $scale): array
    {
        $r = Cache::get($this->key($scale));
        $age = is_array($r) ? (int) CarbonImmutable::parse($r['received_at'])->diffInSeconds(now(), true) : null;
        $live = $age !== null && $age <= (int) config('ricemill.live_weight.stale_after_seconds');

        return [
            'scale_id' => $scale->id, 'code' => $scale->code, 'name' => $scale->name,
            'kg' => is_array($r) ? (float) $r['kg'] : null, 'stable' => $live && (bool) ($r['stable'] ?? false),
            'live' => $live, 'age_seconds' => $age,
        ];
    }

    /** @return list<array{scale_id: string, code: string, name: string, kg: float|null, stable: bool, live: bool, age_seconds: int|null}> */
    public function all(): array
    {
        return Scale::query()->where('status', 'active')->orderBy('code')->get()->map(fn (Scale $s) => $this->current($s))->values()->all();
    }

    /**
     * The settled reading to record: the agent says stable, the reading is fresh, and the last few
     * readings (at least ricemill.gate.min_stable_samples, all from the last seconds) agree within one
     * step of the scale. Otherwise the person is told to wait.
     *
     * @return array{kg: string, samples: list<string>, window_ms: int, device_id: string|null}
     */
    public function stableReading(Scale $scale): array
    {
        $now = $this->current($scale);
        if (! $now['live']) {
            throw new BusinessRuleViolation("No signal from weighbridge {$scale->code}. Check that the gate agent is running.");
        }
        if (! $now['stable']) {
            throw new BusinessRuleViolation('The weight is still moving. Wait until it shows Stable.');
        }
        $need = max(2, (int) config('ricemill.gate.min_stable_samples'));
        $since = (int) now()->getTimestampMs() - ($need + (int) config('ricemill.live_weight.stale_after_seconds')) * 1000;
        $recent = array_values(array_filter((array) Cache::get($this->samplesKey($scale), []), fn (array $s) => $s['ms'] >= $since));
        $recent = array_slice($recent, -$need);
        if (count($recent) < $need) {
            throw new BusinessRuleViolation("Not enough readings yet ({$need} needed). Wait a few seconds and try again.");
        }
        $values = array_column($recent, 'kg');
        if (max($values) - min($values) > (float) $scale->resolution_kg) {
            throw new BusinessRuleViolation('The weight is still moving. Wait until it shows Stable.');
        }
        $last = $recent[count($recent) - 1];

        return [
            'kg' => number_format((float) $last['kg'], 3, '.', ''),
            'samples' => array_map(fn (array $s) => number_format((float) $s['kg'], 3, '.', ''), $recent),
            'window_ms' => $last['ms'] - $recent[0]['ms'],
            'device_id' => Cache::get($this->key($scale))['device_id'] ?? null,
        ];
    }

    private function key(Scale $scale): string
    {
        return 'live-weight:'.$scale->entity_id.':'.$scale->id;
    }

    private function samplesKey(Scale $scale): string
    {
        return 'live-weight-samples:'.$scale->entity_id.':'.$scale->id;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Gate\Scale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The weighbridge reading as it changes, for the screens. The gate agent on the weighbridge PC sends
 * the indicator's value about once a second; it is kept in the cache only (never in the books) and
 * goes stale after a few seconds, so a dead agent or cable shows as "no signal". Recording a weight
 * still goes through the agent's stable-weight capture with its evidence; this is display only.
 */
final class LiveWeight
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly TenantContext $tenant,
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
        Cache::put($this->key($scale->id), [
            'kg' => $kg, 'stable' => (bool) $reading['stable'], 'received_at' => now()->toIso8601String(),
            'device_id' => $this->actor->deviceId(),
        ], now()->addMinutes(10));
    }

    /**
     * @return array{scale_id: string, code: string, name: string, kg: float|null, stable: bool, live: bool, age_seconds: int|null}
     */
    public function current(Scale $scale): array
    {
        $r = Cache::get($this->key($scale->id));
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

    private function key(string $scaleId): string
    {
        return 'live-weight:'.($this->tenant->entityId() ?? '-').':'.$scaleId;
    }
}

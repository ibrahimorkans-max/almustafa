<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Planned maintenance windows ("Under maintenance, back at 6 PM"). While one is active, the web
 * answers with an honest 503 page and the API with 503 + Retry-After; phones, PWA and gate agents
 * keep queueing and sync afterwards. Holders of the secret bypass link (given to the Owner
 * who schedules it) keep working.
 */
final class MaintenanceService
{
    public const BYPASS_COOKIE = 'rm_maintenance_bypass';

    private const CACHE_KEY = 'security:maintenance';

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{id: string, bypass_token: string} the token is shown once and never stored in clear */
    public function schedule(string $message, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $admin = $this->actor->account();
        if ($admin === null || ! $admin->isPlatformAdmin()) {
            throw new BusinessRuleViolation('Only the platform admin can schedule maintenance (it stops every customer).');
        }
        $message = trim($message);
        if (mb_strlen($message) < 5) {
            throw new BusinessRuleViolation('Write the message people will see.');
        }
        if ($endsAt->lessThanOrEqualTo($startsAt) || $endsAt->isPast()) {
            throw new BusinessRuleViolation('The end time must be in the future and after the start.');
        }
        if ($endsAt->diffInHours($startsAt, true) > 24) {
            throw new BusinessRuleViolation('A maintenance window may last at most 24 hours.');
        }

        $id = (string) Str::uuid7();
        $token = Str::random(40);
        DB::table('maintenance_windows')->insert([
            'id' => $id, 'message' => mb_substr($message, 0, 255),
            'starts_at' => $startsAt->utc()->format('Y-m-d H:i:s.v'), 'ends_at' => $endsAt->utc()->format('Y-m-d H:i:s.v'),
            'bypass_hash' => hash('sha256', $token), 'created_by' => $admin->id, 'created_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        ]);
        Cache::forget(self::CACHE_KEY);
        $this->tenant->runAsSystem(fn () => $this->audit->record('security.maintenance.scheduled', null, null,
            ['starts_at' => $startsAt->utc()->toIso8601String(), 'ends_at' => $endsAt->utc()->toIso8601String(), 'message' => $message], ['window_id' => $id]));

        return ['id' => $id, 'bypass_token' => $token];
    }

    public function end(string $id): void
    {
        $admin = $this->actor->account();
        if ($admin === null || ! $admin->isPlatformAdmin()) {
            throw new BusinessRuleViolation('Only the platform admin can end maintenance.');
        }
        $updated = DB::table('maintenance_windows')->where('id', $id)->whereNull('ended_at')
            ->update(['ended_by' => $admin->id, 'ended_at' => now()->utc()->format('Y-m-d H:i:s.v')]);
        if ($updated === 0) {
            throw new BusinessRuleViolation('This maintenance window has already ended.');
        }
        Cache::forget(self::CACHE_KEY);
        $this->tenant->runAsSystem(fn () => $this->audit->record('security.maintenance.ended', null, null, null, ['window_id' => $id]));
    }

    /** The window in force right now, if any. */
    public function active(): ?object
    {
        $now = now();
        foreach ($this->current() as $w) {
            if (CarbonImmutable::parse($w->starts_at, 'UTC')->lessThanOrEqualTo($now) && CarbonImmutable::parse($w->ends_at, 'UTC')->greaterThan($now)) {
                return $w;
            }
        }

        return null;
    }

    /** @return list<object> windows not ended and not yet over (scheduled or running) */
    public function current(): array
    {
        return Cache::remember(self::CACHE_KEY, 30, fn () => DB::table('maintenance_windows')->whereNull('ended_at')
            ->where('ends_at', '>', now()->utc()->format('Y-m-d H:i:s.v'))->orderBy('starts_at')->get()->all());
    }

    public function bypassValid(?string $token, object $window): bool
    {
        return $token !== null && $token !== '' && hash_equals((string) $window->bypass_hash, hash('sha256', $token));
    }
}

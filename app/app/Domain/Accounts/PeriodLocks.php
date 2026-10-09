<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\PeriodLock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Closed months. Locking is direct (`period.lock`); unlocking is a maker-checker request
 * (PeriodUnlock), so books that were filed cannot be quietly reopened.
 */
final class PeriodLocks
{
    public function __construct(private readonly ActorContext $actor) {}

    public static function periodOf(CarbonInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone('Asia/Karachi')->format('Y-m');
    }

    public function isLocked(CarbonInterface $date): bool
    {
        return PeriodLock::query()->where('period', self::periodOf($date))->where('locked', true)->exists();
    }

    public function assertOpen(CarbonInterface $date): void
    {
        if ($this->isLocked($date)) {
            throw new BusinessRuleViolation(sprintf('The books for %s are locked.', CarbonImmutable::instance($date)->setTimezone('Asia/Karachi')->format('M Y')));
        }
    }

    public function lock(string $period): PeriodLock
    {
        if (! ($this->actor->account()?->hasPermission('period.lock') ?? false)) {
            throw new BusinessRuleViolation("You need 'period.lock' for this.");
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new BusinessRuleViolation('Period must look like 2026-09.');
        }
        if ($period >= now('Asia/Karachi')->format('Y-m')) {
            throw new BusinessRuleViolation('Only a finished month can be locked.');
        }

        $lock = PeriodLock::query()->firstOrNew(['period' => $period]);
        if ($lock->exists && $lock->locked) {
            throw new BusinessRuleViolation("{$period} is already locked.");
        }
        $lock->forceFill(['locked' => true, 'locked_by' => $this->actor->accountId(), 'locked_at' => now()])->save();

        return $lock;
    }

    /** Called only by the approved PeriodUnlock request. */
    public function unlock(string $period): PeriodLock
    {
        $lock = PeriodLock::query()->where('period', $period)->where('locked', true)->first()
            ?? throw new BusinessRuleViolation("{$period} is not locked.");
        $lock->forceFill(['locked' => false])->save();

        return $lock;
    }
}

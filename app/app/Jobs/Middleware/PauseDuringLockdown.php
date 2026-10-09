<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Security\Lockdown;
use Closure;

/**
 * Queued work for a locked firm (or any firm during a system lock) waits instead of running:
 * the job goes back on the queue and is tried again later, so nothing is lost or half-done.
 *
 * Usage in a job: public function middleware(): array { return [new PauseDuringLockdown($this->entityId)]; }
 */
final class PauseDuringLockdown
{
    public function __construct(
        private readonly ?string $entityId,
        private readonly int $retryAfterSeconds = 300,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        if (app(Lockdown::class)->isLocked($this->entityId)) {
            $job->release($this->retryAfterSeconds);

            return;
        }
        $next($job);
    }
}

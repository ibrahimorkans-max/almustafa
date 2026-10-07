<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use RuntimeException;

/** Rolls back an event's transaction when it cannot be applied yet (e.g. depends on a record still in transit). */
final class RetryLater extends RuntimeException
{
    public function __construct(public readonly SyncOutcome $outcome)
    {
        parent::__construct((string) $outcome->reason);
    }
}

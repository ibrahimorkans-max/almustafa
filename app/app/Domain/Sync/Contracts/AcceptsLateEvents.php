<?php

declare(strict_types=1);

namespace App\Domain\Sync\Contracts;

/**
 * A handler that judges old events itself instead of the generic "older than the offline limit →
 * sync conflict queue" rule (attendance stores late punches as flagged evidence for HR).
 */
interface AcceptsLateEvents {}

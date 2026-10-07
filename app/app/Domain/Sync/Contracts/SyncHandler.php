<?php

declare(strict_types=1);

namespace App\Domain\Sync\Contracts;

use App\Domain\Sync\SyncEvent;
use App\Domain\Sync\SyncOutcome;
use App\Models\LoginAccount;

/**
 * Applies one kind of client event ("vehicle.upsert"; later "weighment.captured", …).
 *
 * Masters use version checks + last-write-wins (MasterUpsertHandler). Ledger, stock and
 * weighment events (phases 3–5) are append-only: they never overwrite, so they never conflict.
 */
interface SyncHandler
{
    public function type(): string;

    /** The account needs at least one of these permissions to send the event. */
    public function canWrite(LoginAccount $account): bool;

    /**
     * Some actions may be prepared offline but only take effect once confirmed online
     * (large payments, credit approvals). Return true to hold the event as pending_online.
     *
     * @param  array<string, mixed>  $payload
     */
    public function requiresOnlineConfirmation(array $payload): bool;

    /** Runs inside a transaction; must not catch-and-swallow database errors. */
    public function apply(SyncEvent $event, LoginAccount $account): SyncOutcome;
}

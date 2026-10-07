<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Sync\SyncRegistry;
use App\Models\LoginAccount;
use App\Models\Masters\MasterModel;

/**
 * One policy for all master data, driven by the same permission table the sync API uses,
 * so the admin screens and devices can never disagree. Masters are never deleted
 * (archiving arrives in phase 7).
 */
abstract class MasterPolicy
{
    /** Sync type of the model this policy guards (vehicle, party, …). */
    protected const TYPE = '';

    public function __construct(private readonly SyncRegistry $registry) {}

    public function viewAny(LoginAccount $user): bool
    {
        return $this->registry->canRead($user, static::TYPE);
    }

    public function view(LoginAccount $user, MasterModel $record): bool
    {
        return $this->registry->canRead($user, $record::SYNC_TYPE);
    }

    public function create(LoginAccount $user): bool
    {
        return (bool) $this->registry->handler(static::TYPE.'.upsert')?->canWrite($user);
    }

    public function update(LoginAccount $user, MasterModel $record): bool
    {
        return (bool) $this->registry->handler($record::SYNC_TYPE.'.upsert')?->canWrite($user);
    }

    public function delete(LoginAccount $user, MasterModel $record): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}

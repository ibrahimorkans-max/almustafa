<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoginAccount;
use App\Models\SyncConflict;

final class SyncConflictPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('sync.review');
    }

    public function view(LoginAccount $user, SyncConflict $conflict): bool
    {
        return $user->hasPermission('sync.review');
    }

    public function resolve(LoginAccount $user, SyncConflict $conflict): bool
    {
        return $conflict->isOpen() && $user->hasPermission('sync.review');
    }

    public function create(LoginAccount $user): bool
    {
        return false;
    }

    public function update(LoginAccount $user, SyncConflict $conflict): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, SyncConflict $conflict): bool
    {
        return false;
    }
}

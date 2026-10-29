<?php

declare(strict_types=1);

namespace App\Policies\Masters;

use App\Models\LoginAccount;
use App\Models\Masters\PaddyVariety;

/** Paddy varieties: seen with 'master.view', added and changed with 'master.manage'; never deleted (deactivated). */
final class PaddyVarietyPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('master.view') || $user->hasPermission('master.manage');
    }

    public function view(LoginAccount $user, PaddyVariety $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(LoginAccount $user): bool
    {
        return $user->hasPermission('master.manage');
    }

    public function update(LoginAccount $user, PaddyVariety $record): bool
    {
        return $user->hasPermission('master.manage');
    }

    public function delete(LoginAccount $user, PaddyVariety $record): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}

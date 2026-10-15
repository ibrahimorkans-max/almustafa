<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoginAccount;
use App\Models\Role;

final class LoginAccountPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('user.view');
    }

    public function view(LoginAccount $user, LoginAccount $account): bool
    {
        return $user->hasPermission('user.view');
    }

    public function create(LoginAccount $user): bool
    {
        return $user->hasPermission('user.manage');
    }

    /** Only an Owner may manage Owner accounts. */
    public function update(LoginAccount $user, LoginAccount $account): bool
    {
        return $user->hasPermission('user.manage')
            && ($user->isOwner() || ! $account->isOwner());
    }

    /** Managing your own account through the admin screens is not allowed (no self-unlock, self-role-change). */
    public function manageSecurity(LoginAccount $user, LoginAccount $account): bool
    {
        return $this->update($user, $account) && $user->person_id !== $account->person_id;
    }

    /**
     * A role may be assigned only if the assigner holds every permission in it
     * (prevents privilege escalation); the Owner role only by an Owner.
     */
    public function assignRole(LoginAccount $user, Role $role): bool
    {
        if ($user->isOwner()) {
            return true;
        }
        if ($role->is_super_admin) {
            return false;
        }

        return array_diff($role->permissionCodes(), $user->permissionCodes()) === [];
    }

    /** Accounts are disabled, never deleted. */
    public function delete(LoginAccount $user, LoginAccount $account): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}

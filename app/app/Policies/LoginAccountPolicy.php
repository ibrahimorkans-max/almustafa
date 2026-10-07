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

    /** Only a Super Admin may manage Super Admin accounts. */
    public function update(LoginAccount $user, LoginAccount $account): bool
    {
        return $user->hasPermission('user.manage')
            && ($user->isSuperAdmin() || ! $account->isSuperAdmin());
    }

    /** Managing your own account through the admin screens is not allowed (no self-unlock, self-role-change). */
    public function manageSecurity(LoginAccount $user, LoginAccount $account): bool
    {
        return $this->update($user, $account) && $user->person_id !== $account->person_id;
    }

    /**
     * A role may be assigned only if the assigner holds every permission in it
     * (prevents privilege escalation); the Super Admin role only by a Super Admin.
     */
    public function assignRole(LoginAccount $user, Role $role): bool
    {
        if ($user->isSuperAdmin()) {
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

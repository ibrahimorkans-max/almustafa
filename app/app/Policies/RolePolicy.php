<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoginAccount;
use App\Models\Role;

/** Roles are read-only in the UI; grants change through maker-checker requests. */
final class RolePolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('role.view');
    }

    public function view(LoginAccount $user, Role $role): bool
    {
        return $user->hasPermission('role.view');
    }

    public function requestChange(LoginAccount $user, Role $role): bool
    {
        // The Owner role (shared by every customer) is the platform admin's.
        return $user->hasPermission('role.manage') && ((! $role->is_super_admin && $role->entity_id !== null) || $user->isPlatformAdmin());
    }

    public function create(LoginAccount $user): bool
    {
        return false;
    }

    public function update(LoginAccount $user, Role $role): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, Role $role): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}

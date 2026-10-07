<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\LoginAccount;

final class DevicePolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('device.view') || $user->hasPermission('device.manage');
    }

    public function view(LoginAccount $user, Device $device): bool
    {
        return $this->viewAny($user);
    }

    /** Revoke / request remote wipe. A Super Admin's devices only by a Super Admin. */
    public function manage(LoginAccount $user, Device $device): bool
    {
        return $user->hasPermission('device.manage')
            && ($user->isSuperAdmin() || ! ($device->loginAccount?->isSuperAdmin() ?? true));
    }

    public function create(LoginAccount $user): bool
    {
        return false;
    }

    public function update(LoginAccount $user, Device $device): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, Device $device): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}

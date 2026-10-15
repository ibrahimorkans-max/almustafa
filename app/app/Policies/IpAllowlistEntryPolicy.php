<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;

/** Network restrictions are an Owner control. */
final class IpAllowlistEntryPolicy
{
    private function allowed(LoginAccount $user): bool
    {
        return $user->isOwner() && $user->hasPermission('user.manage');
    }

    public function viewAny(LoginAccount $user): bool
    {
        return $this->allowed($user);
    }

    public function view(LoginAccount $user, IpAllowlistEntry $entry): bool
    {
        return $this->allowed($user);
    }

    public function create(LoginAccount $user): bool
    {
        return $this->allowed($user);
    }

    public function update(LoginAccount $user, IpAllowlistEntry $entry): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, IpAllowlistEntry $entry): bool
    {
        return $this->allowed($user);
    }
}

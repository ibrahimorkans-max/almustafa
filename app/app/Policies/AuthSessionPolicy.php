<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuthSession;
use App\Models\LoginAccount;

final class AuthSessionPolicy
{
    /** Everyone may list their own sessions; the list is filtered for non-admins. */
    public function viewAny(LoginAccount $user): bool
    {
        return $user->isActive();
    }

    public function view(LoginAccount $user, AuthSession $session): bool
    {
        return $session->login_account_id === $user->id || $user->hasPermission('session.view_all');
    }

    public function revoke(LoginAccount $user, AuthSession $session): bool
    {
        return $session->login_account_id === $user->id || $user->hasPermission('session.revoke_any');
    }

    public function create(LoginAccount $user): bool
    {
        return false;
    }

    public function update(LoginAccount $user, AuthSession $session): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, AuthSession $session): bool
    {
        return false;
    }
}

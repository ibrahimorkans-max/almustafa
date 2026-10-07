<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoginAccount;
use App\Models\Masters\PartyBankAccount;

/** Bank accounts are listed (masked) to master viewers; every change goes through maker-checker. */
final class PartyBankAccountPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('master.view');
    }

    public function view(LoginAccount $user, PartyBankAccount $account): bool
    {
        return $user->hasPermission('master.view');
    }

    public function create(LoginAccount $user): bool
    {
        return false;
    }

    public function update(LoginAccount $user, PartyBankAccount $account): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, PartyBankAccount $account): bool
    {
        return false;
    }
}

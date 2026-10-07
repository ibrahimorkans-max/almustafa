<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Permission-table driven policy used for the procurement and gate models. Records are
 * changed only through domain services (which re-check permissions); edit/delete in the
 * generic sense is off, deletion always.
 */
abstract class Phase3Policies
{
    /** @return list<string> any of these lets the user see the list */
    abstract protected function viewPermissions(): array;

    /** @return list<string> any of these lets the user create */
    abstract protected function createPermissions(): array;

    /** @param  list<string>  $permissions */
    protected function any(LoginAccount $user, array $permissions): bool
    {
        foreach ($permissions as $p) {
            if ($user->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    public function viewAny(LoginAccount $user): bool
    {
        return $this->any($user, $this->viewPermissions());
    }

    public function view(LoginAccount $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(LoginAccount $user): bool
    {
        return $this->any($user, $this->createPermissions());
    }

    public function update(LoginAccount $user, Model $record): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}

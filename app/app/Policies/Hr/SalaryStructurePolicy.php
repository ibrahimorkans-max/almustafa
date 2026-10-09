<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** Salary versions are append-only: changes are new versions. */
final class SalaryStructurePolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('hr.salary.view');
    }

    public function view(LoginAccount $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(LoginAccount $user): bool
    {
        return $user->hasPermission('hr.salary.edit');
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

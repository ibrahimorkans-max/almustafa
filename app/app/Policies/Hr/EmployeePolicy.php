<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** An employee is never deleted; exits keep the record. */
final class EmployeePolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('hr.employee.view');
    }

    public function view(LoginAccount $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(LoginAccount $user): bool
    {
        return $user->hasPermission('hr.employee.edit');
    }

    public function update(LoginAccount $user, Model $record): bool
    {
        return $user->hasPermission('hr.employee.edit');
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

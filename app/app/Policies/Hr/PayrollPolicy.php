<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Payroll records (advances, payroll runs …): seen by whoever prepares or approves payroll, created by
 * those who prepare it; changed only through their services (never edited or deleted on a screen).
 */
class PayrollPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('hr.payroll.run') || $user->hasPermission('hr.payroll.approve');
    }

    public function view(LoginAccount $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(LoginAccount $user): bool
    {
        return $user->hasPermission('hr.payroll.run');
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

<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** Allowance rules change everyone's pay: seen by payroll, set or changed only by its approvers. */
final class PayrollRulePolicy extends PayrollPolicy
{
    public function create(LoginAccount $user): bool
    {
        return $user->hasPermission('hr.payroll.approve');
    }

    public function update(LoginAccount $user, Model $record): bool
    {
        return $user->hasPermission('hr.payroll.approve');
    }
}

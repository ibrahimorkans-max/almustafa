<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\LoginAccount;

/** Piece work is recorded by supervisors (who see the work) as well as by payroll. */
final class PieceWorkPolicy extends PayrollPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return parent::viewAny($user) || $user->hasPermission('hr.attendance.review');
    }

    public function create(LoginAccount $user): bool
    {
        return $user->hasPermission('hr.payroll.run') || $user->hasPermission('hr.attendance.review');
    }
}

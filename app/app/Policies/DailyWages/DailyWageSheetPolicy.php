<?php

declare(strict_types=1);

namespace App\Policies\DailyWages;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** Wage sheets: recorded with wages.daily.manage, booked and paid through DailyWages; never edited or deleted. */
final class DailyWageSheetPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('wages.daily.manage') || $user->hasPermission('wages.daily.pay');
    }

    public function view(LoginAccount $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(LoginAccount $user): bool
    {
        return $user->hasPermission('wages.daily.manage');
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

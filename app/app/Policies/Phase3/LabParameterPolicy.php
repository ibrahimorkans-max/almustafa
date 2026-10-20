<?php

declare(strict_types=1);

namespace App\Policies\Phase3;

use App\Models\LoginAccount;
use App\Policies\Phase3Policies;
use Illuminate\Database\Eloquent\Model;

/** The lab tests and their limits: seen by the lab and purchase staff, set by whoever holds lab.limits. */
final class LabParameterPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['lab.limits', 'lab.test', 'lab.decide', 'purchase.negotiate', 'purchase.approve'];
    }

    protected function createPermissions(): array
    {
        return ['lab.limits'];
    }

    public function update(LoginAccount $user, Model $record): bool
    {
        return $user->hasPermission('lab.limits');
    }
}

<?php

declare(strict_types=1);

namespace App\Policies\Phase3;

use App\Models\LoginAccount;
use App\Policies\Phase3Policies;
use Illuminate\Database\Eloquent\Model;

final class ScalePolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['weighment.capture', 'master.view'];
    }

    protected function createPermissions(): array
    {
        return ['master.manage'];
    }

    public function update(LoginAccount $user, Model $record): bool
    {
        return $user->hasPermission('master.manage');
    }
}

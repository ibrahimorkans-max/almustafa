<?php

declare(strict_types=1);

namespace App\Policies\Phase4;

use App\Models\LoginAccount;
use App\Policies\Phase3Policies;
use Illuminate\Database\Eloquent\Model;

final class OutturnStandardPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['production.view'];
    }

    protected function createPermissions(): array
    {
        return ['batch.manage'];
    }

    /** Standards are configuration; closed batches keep the copy frozen in their snapshot. */
    public function update(LoginAccount $user, Model $record): bool
    {
        return $user->hasPermission('batch.manage');
    }
}

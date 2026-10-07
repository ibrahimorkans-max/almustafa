<?php

declare(strict_types=1);

namespace App\Policies\Phase3;

use App\Models\LoginAccount;
use App\Policies\Phase3Policies;
use Illuminate\Database\Eloquent\Model;

final class SaudaPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['deal.view', 'deal.view_own'];
    }

    protected function createPermissions(): array
    {
        return ['deal.create'];
    }

    /** Unapproved drafts can still be edited by deal makers. */
    public function update(LoginAccount $user, Model $record): bool
    {
        return $user->hasPermission('deal.create') && $record->getAttribute('status') === 'draft';
    }
}

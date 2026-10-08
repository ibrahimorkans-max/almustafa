<?php

declare(strict_types=1);

namespace App\Policies\Phase5;

use App\Models\Accounts\LedgerAccount;
use App\Models\LoginAccount;
use App\Policies\Phase3Policies;
use Illuminate\Database\Eloquent\Model;

final class LedgerAccountPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['ledger.view'];
    }

    protected function createPermissions(): array
    {
        return ['ledger.post'];
    }

    /** Names and status of general and bank ledgers; party and system ledgers are managed by the application. */
    public function update(LoginAccount $user, Model $record): bool
    {
        return $record instanceof LedgerAccount && in_array($record->kind, ['general', 'bank'], true) && $user->hasPermission('ledger.post');
    }
}

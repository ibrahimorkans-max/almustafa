<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** Recurring expense templates: seen with the books, set up and changed by those who post vouchers. */
final class RecurringExpensePolicy extends ReimbursementPolicy
{
    protected array $viewers = ['ledger.view', 'ledger.post'];

    protected array $creators = ['ledger.post'];

    public function update(LoginAccount $user, Model $record): bool
    {
        return $user->hasPermission('ledger.post');
    }
}

<?php

declare(strict_types=1);

namespace App\Policies\Hr;

/** Expense categories: seen with the books, set up by those who post vouchers. */
final class ExpenseCategoryPolicy extends ReimbursementPolicy
{
    protected array $viewers = ['ledger.view', 'ledger.post'];

    protected array $creators = ['ledger.post'];
}

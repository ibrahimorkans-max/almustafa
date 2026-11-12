<?php

declare(strict_types=1);

namespace App\Policies\Accounts;

use App\Policies\Phase3Policies;

/** Old records from paper: seen with the books or the stock, entered by those who post vouchers or adjust stock. */
final class PaperRecordPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['ledger.view', 'stock.view'];
    }

    protected function createPermissions(): array
    {
        return ['ledger.post', 'stock.adjust'];
    }
}

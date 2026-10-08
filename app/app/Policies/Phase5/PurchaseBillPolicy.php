<?php

declare(strict_types=1);

namespace App\Policies\Phase5;

use App\Policies\Phase3Policies;

final class PurchaseBillPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['ledger.view'];
    }

    protected function createPermissions(): array
    {
        return [];
    }
}

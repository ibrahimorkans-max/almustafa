<?php

declare(strict_types=1);

namespace App\Policies\Phase5;

use App\Policies\Phase3Policies;

final class PaymentPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['ledger.view', 'payment.create', 'payment.approve'];
    }

    protected function createPermissions(): array
    {
        return ['payment.create'];
    }
}

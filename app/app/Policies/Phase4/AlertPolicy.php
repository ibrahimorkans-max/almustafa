<?php

declare(strict_types=1);

namespace App\Policies\Phase4;

use App\Policies\Phase3Policies;

final class AlertPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        // HR and accounts open the list too; it shows each person only the alerts meant for them.
        return ['stock.view', 'production.view', 'hr.employee.view', 'ledger.view'];
    }

    protected function createPermissions(): array
    {
        return [];
    }
}

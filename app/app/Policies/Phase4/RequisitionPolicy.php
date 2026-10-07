<?php

declare(strict_types=1);

namespace App\Policies\Phase4;

use App\Policies\Phase3Policies;

final class RequisitionPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['stock.view', 'requisition.create', 'requisition.approve'];
    }

    protected function createPermissions(): array
    {
        return ['requisition.create'];
    }
}

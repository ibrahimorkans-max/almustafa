<?php

declare(strict_types=1);

namespace App\Policies\Phase4;

use App\Policies\Phase3Policies;

final class AlertPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['stock.view', 'production.view'];
    }

    protected function createPermissions(): array
    {
        return [];
    }
}

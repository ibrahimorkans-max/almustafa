<?php

declare(strict_types=1);

namespace App\Policies\Phase7;

use App\Policies\Phase3Policies;

final class ClaimPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['claim.view', 'claim.manage', 'claim.approve'];
    }

    protected function createPermissions(): array
    {
        return ['claim.manage'];
    }
}

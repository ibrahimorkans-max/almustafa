<?php

declare(strict_types=1);

namespace App\Policies\Phase6;

use App\Policies\Phase3Policies;

final class DispatchOrderPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['sales.view', 'dispatch.manage'];
    }

    protected function createPermissions(): array
    {
        return ['dispatch.manage'];
    }
}

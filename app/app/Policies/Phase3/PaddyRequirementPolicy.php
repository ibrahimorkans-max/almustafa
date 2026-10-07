<?php

declare(strict_types=1);

namespace App\Policies\Phase3;

use App\Policies\Phase3Policies;

final class PaddyRequirementPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['requirement.broadcast', 'quote.view'];
    }

    protected function createPermissions(): array
    {
        return ['requirement.broadcast'];
    }
}

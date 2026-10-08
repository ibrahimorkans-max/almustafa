<?php

declare(strict_types=1);

namespace App\Policies\Phase7;

use App\Policies\Phase3Policies;

final class PriceListPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['pricing.view'];
    }

    protected function createPermissions(): array
    {
        return ['pricing.rate.edit'];
    }
}

<?php

declare(strict_types=1);

namespace App\Policies\Phase7;

use App\Policies\Phase3Policies;

final class MarkupSettingPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['pricing.margin.view', 'pricing.markup.set'];
    }

    protected function createPermissions(): array
    {
        return ['pricing.markup.set'];
    }
}

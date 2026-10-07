<?php

declare(strict_types=1);

namespace App\Policies\Phase3;

use App\Policies\Phase3Policies;

final class QuotePolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['quote.view', 'quote.manage'];
    }

    protected function createPermissions(): array
    {
        return ['quote.manage'];
    }
}

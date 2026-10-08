<?php

declare(strict_types=1);

namespace App\Policies\Phase5;

use App\Policies\Phase3Policies;

final class BankStatementLinePolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['bank.view'];
    }

    protected function createPermissions(): array
    {
        return [];
    }
}

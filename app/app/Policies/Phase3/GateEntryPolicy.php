<?php

declare(strict_types=1);

namespace App\Policies\Phase3;

use App\Policies\Phase3Policies;

final class GateEntryPolicy extends Phase3Policies
{
    protected function viewPermissions(): array
    {
        return ['gate.entry', 'weighment.capture', 'lab.test', 'lab.decide'];
    }

    protected function createPermissions(): array
    {
        return ['gate.entry'];
    }
}

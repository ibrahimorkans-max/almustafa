<?php

declare(strict_types=1);

namespace App\Policies\Masters;

use App\Policies\MasterPolicy;

final class DriverPolicy extends MasterPolicy
{
    protected const TYPE = 'driver';
}

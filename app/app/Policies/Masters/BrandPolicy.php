<?php

declare(strict_types=1);

namespace App\Policies\Masters;

use App\Policies\MasterPolicy;

final class BrandPolicy extends MasterPolicy
{
    protected const TYPE = 'brand';
}

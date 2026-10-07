<?php

declare(strict_types=1);

namespace App\Policies\Masters;

use App\Policies\MasterPolicy;

final class TaxRatePolicy extends MasterPolicy
{
    protected const TYPE = 'tax_rate';
}

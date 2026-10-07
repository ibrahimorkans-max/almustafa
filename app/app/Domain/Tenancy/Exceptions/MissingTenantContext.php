<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class MissingTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No entity is selected for this operation.');
    }
}

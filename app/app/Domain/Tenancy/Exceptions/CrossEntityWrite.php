<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class CrossEntityWrite extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct("Refusing to write {$model} belonging to another entity.");
    }
}

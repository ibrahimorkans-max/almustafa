<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class StaleModelException extends RuntimeException
{
    public function __construct(public readonly Model $model)
    {
        parent::__construct(sprintf(
            '%s %s was changed by someone else. Reload and try again.',
            class_basename($model),
            (string) $model->getKey(),
        ));
    }
}

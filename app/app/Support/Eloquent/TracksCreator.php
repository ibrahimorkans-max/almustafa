<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use App\Domain\Auth\ActorContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Fills `created_by` with the acting login account.
 *
 * @mixin Model
 */
trait TracksCreator
{
    public static function bootTracksCreator(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('created_by') === null) {
                $model->setAttribute('created_by', app(ActorContext::class)->accountId());
            }
        });
    }
}

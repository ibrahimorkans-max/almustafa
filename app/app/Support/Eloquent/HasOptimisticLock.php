<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Optimistic locking on the `version` column: every update is
 * `... WHERE id = ? AND version = <loaded version>` and bumps the version.
 * If someone else saved first, StaleModelException is thrown instead of
 * silently overwriting their change.
 *
 * @mixin Model
 */
trait HasOptimisticLock
{
    public static function bootHasOptimisticLock(): void
    {
        // Set explicitly: the DB default is not read back into a freshly created model.
        static::creating(function (Model $model): void {
            if ($model->getAttribute('version') === null) {
                $model->setAttribute('version', 1);
            }
        });

        static::updating(function (Model $model): void {
            $model->setAttribute('version', ((int) $model->getOriginal('version')) + 1);
        });
    }

    /**
     * Mirrors Model::performUpdate, but checks the affected row count.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $affected = $this->setKeysForSaveQuery($query)
                ->where($this->qualifyColumn('version'), (int) $this->getOriginal('version'))
                ->update($dirty);

            if ($affected === 0) {
                throw new StaleModelException($this);
            }

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }
}

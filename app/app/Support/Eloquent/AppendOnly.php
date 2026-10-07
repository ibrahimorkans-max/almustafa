<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * For fact tables (weighments, lab tests, gate/sauda events, quote revisions):
 * rows are inserted once and never changed or deleted. Corrections are new rows that
 * supersede earlier ones. Enforced here and by database triggers.
 *
 * @mixin Model
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn (Model $m) => throw new LogicException(class_basename($m).' is append-only; record a correction instead.'));
        static::deleting(fn (Model $m) => throw new LogicException(class_basename($m).' is append-only and cannot be deleted.'));
    }

    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Working screens never show archived records: not in the list, not by searching, not by opening a
 * record's address. There is no filter to show them. Archived records are only seen and restored on
 * the Archive screen (App\Filament\Pages\ArchiveSearch).
 */
trait HidesArchived
{
    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return $query->whereNull($query->getModel()->qualifyColumn('archived_at'));
    }
}

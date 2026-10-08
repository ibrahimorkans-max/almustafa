<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;

/** Working lists hide archived rows by default; the filter shows them (or only them) on request. */
final class ArchivedFilter
{
    public static function make(): TernaryFilter
    {
        return TernaryFilter::make('archived')->label('Archived')->default(false)
            ->placeholder('All')->trueLabel('Archived only')->falseLabel('Not archived')
            ->queries(
                true: fn (Builder $q) => $q->whereNotNull($q->getModel()->qualifyColumn('archived_at')),
                false: fn (Builder $q) => $q->whereNull($q->getModel()->qualifyColumn('archived_at')),
                blank: fn (Builder $q) => $q,
            );
    }
}

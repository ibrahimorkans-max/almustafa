<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyncConflictResource\Pages;

use App\Filament\Resources\SyncConflictResource;
use Filament\Resources\Pages\ListRecords;

class ListSyncConflicts extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = SyncConflictResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\GateEntryResource\Pages;

use App\Filament\Resources\Gate\GateEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGateEntries extends ListRecords
{
    protected static string $resource = GateEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Vehicle arrived')];
    }
}

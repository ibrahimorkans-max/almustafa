<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntityResource\Pages;

use App\Filament\Resources\EntityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEntities extends ListRecords
{
    protected static string $resource = EntityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

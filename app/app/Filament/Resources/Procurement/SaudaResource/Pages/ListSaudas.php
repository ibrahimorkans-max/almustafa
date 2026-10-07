<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\SaudaResource\Pages;

use App\Filament\Resources\Procurement\SaudaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSaudas extends ListRecords
{
    protected static string $resource = SaudaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

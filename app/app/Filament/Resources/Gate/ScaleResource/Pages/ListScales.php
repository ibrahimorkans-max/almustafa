<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\ScaleResource\Pages;

use App\Filament\Resources\Gate\ScaleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScales extends ListRecords
{
    protected static string $resource = ScaleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

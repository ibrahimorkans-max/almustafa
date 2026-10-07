<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionBatchResource\Pages;

use App\Filament\Resources\Production\ProductionBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductionBatches extends ListRecords
{
    protected static string $resource = ProductionBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Open batch')];
    }
}

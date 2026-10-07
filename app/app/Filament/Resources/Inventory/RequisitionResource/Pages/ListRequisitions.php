<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\RequisitionResource\Pages;

use App\Filament\Resources\Inventory\RequisitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRequisitions extends ListRecords
{
    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New requisition')];
    }
}

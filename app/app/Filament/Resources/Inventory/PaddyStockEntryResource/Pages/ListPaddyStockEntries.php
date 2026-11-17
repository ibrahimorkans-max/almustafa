<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\PaddyStockEntryResource\Pages;

use App\Filament\Resources\Inventory\PaddyStockEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaddyStockEntries extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = PaddyStockEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New paddy stock entry'))];
    }
}

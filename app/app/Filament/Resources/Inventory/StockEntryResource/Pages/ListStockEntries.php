<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockEntryResource\Pages;

use App\Filament\Resources\Inventory\StockEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListStockEntries extends ListRecords
{
    protected static string $resource = StockEntryResource::class;
}

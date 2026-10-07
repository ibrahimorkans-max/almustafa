<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockBalanceResource\Pages;

use App\Filament\Resources\Inventory\StockBalanceResource;
use App\Filament\Resources\Inventory\StockCountResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListStockBalances extends ListRecords
{
    protected static string $resource = StockBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('count')->label('Record physical count')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn () => Filament::auth()->user()?->can('stock.adjust') ?? false)
                ->url(StockCountResource::getUrl('index')),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\QuickBillResource\Pages;

use App\Filament\Resources\Sales\QuickBillResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuickBills extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = QuickBillResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New quick bill'))->icon('heroicon-o-plus')];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pricing\PriceListResource\Pages;

use App\Filament\Resources\Pricing\PriceListResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPriceLists extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New price list'))];
    }
}

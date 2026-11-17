<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\QuoteResource\Pages;

use App\Filament\Resources\Procurement\QuoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuotes extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Record offer'))];
    }
}

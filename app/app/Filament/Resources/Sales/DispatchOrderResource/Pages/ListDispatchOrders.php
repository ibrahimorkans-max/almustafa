<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\DispatchOrderResource\Pages;

use App\Filament\Resources\Sales\DispatchOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDispatchOrders extends ListRecords
{
    protected static string $resource = DispatchOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New dispatch order')];
    }
}

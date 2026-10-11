<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\AdvanceResource\Pages;

use App\Filament\Resources\Hr\AdvanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdvances extends ListRecords
{
    protected static string $resource = AdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Request advance or loan')];
    }
}

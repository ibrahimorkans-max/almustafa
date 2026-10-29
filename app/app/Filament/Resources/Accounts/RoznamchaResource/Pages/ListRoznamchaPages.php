<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RoznamchaResource\Pages;

use App\Filament\Resources\Accounts\RoznamchaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoznamchaPages extends ListRecords
{
    protected static string $resource = RoznamchaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a page')];
    }
}

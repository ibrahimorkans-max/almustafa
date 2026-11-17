<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\PartyResource\Pages;

use App\Filament\Resources\Masters\PartyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListParties extends ListRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

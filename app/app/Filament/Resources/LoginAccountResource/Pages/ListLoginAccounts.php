<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoginAccountResource\Pages;

use App\Filament\Resources\LoginAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLoginAccounts extends ListRecords
{
    protected static string $resource = LoginAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

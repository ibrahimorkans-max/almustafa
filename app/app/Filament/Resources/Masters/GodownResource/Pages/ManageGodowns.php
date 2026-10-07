<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\GodownResource\Pages;

use App\Filament\Resources\Masters\GodownResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGodowns extends ManageRecords
{
    protected static string $resource = GodownResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

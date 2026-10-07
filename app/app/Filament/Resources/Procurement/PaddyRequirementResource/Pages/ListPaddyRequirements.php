<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\PaddyRequirementResource\Pages;

use App\Filament\Resources\Procurement\PaddyRequirementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaddyRequirements extends ListRecords
{
    protected static string $resource = PaddyRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

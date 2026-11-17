<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\VehicleResource\Pages;

use App\Filament\Resources\Masters\VehicleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVehicles extends ManageRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\DriverResource\Pages;

use App\Filament\Resources\Masters\DriverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDrivers extends ManageRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

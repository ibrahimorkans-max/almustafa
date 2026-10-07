<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\OutturnStandardResource\Pages;

use App\Filament\Resources\Production\OutturnStandardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOutturnStandards extends ManageRecords
{
    protected static string $resource = OutturnStandardResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

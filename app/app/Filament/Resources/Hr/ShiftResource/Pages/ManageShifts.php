<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\ShiftResource\Pages;

use App\Filament\Resources\Hr\ShiftResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShifts extends ManageRecords
{
    protected static string $resource = ShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

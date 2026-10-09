<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\HolidayResource\Pages;

use App\Filament\Resources\Hr\HolidayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHolidays extends ManageRecords
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\Pages;

use App\Filament\Resources\Hr\EmployeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add employee')];
    }
}

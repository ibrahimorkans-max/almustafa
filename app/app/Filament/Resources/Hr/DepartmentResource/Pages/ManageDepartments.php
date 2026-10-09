<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\DepartmentResource\Pages;

use App\Filament\Resources\Hr\DepartmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDepartments extends ManageRecords
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

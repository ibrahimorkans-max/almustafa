<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\SalaryComponentResource\Pages;

use App\Filament\Resources\Hr\SalaryComponentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSalaryComponents extends ManageRecords
{
    protected static string $resource = SalaryComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

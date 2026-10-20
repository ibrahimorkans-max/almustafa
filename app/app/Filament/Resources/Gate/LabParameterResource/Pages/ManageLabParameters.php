<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\LabParameterResource\Pages;

use App\Filament\Resources\Gate\LabParameterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLabParameters extends ManageRecords
{
    protected static string $resource = LabParameterResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add lab test')->mutateFormDataUsing(fn (array $data) => LabParameterResource::limits($data))];
    }
}

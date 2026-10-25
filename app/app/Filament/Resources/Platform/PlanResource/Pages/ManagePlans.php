<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\PlanResource\Pages;

use App\Filament\Resources\Platform\PlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePlans extends ManageRecords
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New plan')->mutateFormDataUsing(fn (array $data) => PlanResource::prices($data))];
    }
}

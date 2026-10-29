<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\PaddyVarietyResource\Pages;

use App\Filament\Resources\Masters\PaddyVarietyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePaddyVarieties extends ManageRecords
{
    protected static string $resource = PaddyVarietyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => PaddyVarietyResource::store($data, field: 'mountedActionsData.0.name'))];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\DesignationResource\Pages;

use App\Filament\Resources\Hr\DesignationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDesignations extends ManageRecords
{
    protected static string $resource = DesignationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

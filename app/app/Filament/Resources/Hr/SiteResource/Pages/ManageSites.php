<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\SiteResource\Pages;

use App\Filament\Resources\Hr\SiteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSites extends ManageRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

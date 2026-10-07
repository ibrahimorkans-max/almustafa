<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\BrandResource\Pages;

use App\Filament\Resources\Masters\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBrands extends ManageRecords
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

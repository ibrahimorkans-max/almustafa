<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\TaxRateResource\Pages;

use App\Filament\Resources\Masters\TaxRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTaxRates extends ManageRecords
{
    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

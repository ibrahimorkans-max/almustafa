<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesSaudaResource\Pages;

use App\Domain\Procurement\SaudaService;
use App\Filament\Resources\Procurement\SaudaResource\Pages\CreateSauda;
use App\Filament\Resources\Sales\SalesSaudaResource;
use Illuminate\Database\Eloquent\Model;

class CreateSalesSauda extends CreateSauda
{
    protected static string $resource = SalesSaudaResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaudaService::class)->create(SalesSaudaResource::toTerms($data));
    }
}

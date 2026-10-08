<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesSaudaResource\Pages;

use App\Filament\Resources\Procurement\SaudaResource\Pages\ListSaudas;
use App\Filament\Resources\Sales\SalesSaudaResource;

class ListSalesSaudas extends ListSaudas
{
    protected static string $resource = SalesSaudaResource::class;
}

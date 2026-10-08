<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesBillResource\Pages;

use App\Filament\Resources\Sales\SalesBillResource;
use Filament\Resources\Pages\ListRecords;

class ListSalesBills extends ListRecords
{
    protected static string $resource = SalesBillResource::class;
}

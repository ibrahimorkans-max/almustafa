<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PurchaseBillResource\Pages;

use App\Filament\Resources\Accounts\PurchaseBillResource;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseBills extends ListRecords
{
    protected static string $resource = PurchaseBillResource::class;
}

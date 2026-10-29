<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banking\BankEmailTransactionResource\Pages;

use App\Filament\Resources\Banking\BankEmailTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListBankEmailTransactions extends ListRecords
{
    protected static string $resource = BankEmailTransactionResource::class;
}

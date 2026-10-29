<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banking\BankEmailTransactionResource\Pages;

use App\Filament\Resources\Banking\BankEmailTransactionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewBankEmailTransaction extends ViewRecord
{
    protected static string $resource = BankEmailTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return BankEmailTransactionResource::reviewActions(Action::class);
    }
}

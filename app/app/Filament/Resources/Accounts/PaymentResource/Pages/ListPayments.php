<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PaymentResource\Pages;

use App\Filament\Resources\Accounts\PaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Request payment')];
    }
}

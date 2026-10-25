<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\PaymentMethodResource\Pages;

use App\Filament\Resources\Platform\PaymentMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePaymentMethods extends ManageRecords
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New payment method')->mutateFormDataUsing(fn (array $data) => PaymentMethodResource::withQr($data))];
    }
}

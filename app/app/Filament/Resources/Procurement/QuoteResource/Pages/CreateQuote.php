<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\QuoteResource\Pages;

use App\Domain\Procurement\QuoteService;
use App\Filament\Resources\Procurement\QuoteResource;
use App\Filament\Support\Units;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(QuoteService::class)->submit([
            'requirement_id' => $data['requirement_id'] ?? null,
            'party_id' => $data['party_id'],
            'broker_party_id' => $data['broker_party_id'] ?? null,
            'product_id' => $data['product_id'],
            'quantity_kg' => Units::quintalToKg((string) $data['quantity_quintal']),
            'rate_paise_per_quintal' => Units::rupeesToPaise((string) $data['rate_rupees']),
            'valid_until' => $data['valid_until'] ?? null,
        ], (string) $data['by'], $data['note'] ?? null);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

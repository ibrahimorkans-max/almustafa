<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pricing\PriceListResource\Pages;

use App\Domain\Pricing\PricingService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Pricing\PriceListResource;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePriceList extends CreateRecord
{
    protected static string $resource = PriceListResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PricingService::class)->createList(
                (string) $data['name'],
                CarbonImmutable::parse($data['valid_from']),
                filled($data['valid_to'] ?? null) ? CarbonImmutable::parse($data['valid_to']) : null,
            );
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.valid_to' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

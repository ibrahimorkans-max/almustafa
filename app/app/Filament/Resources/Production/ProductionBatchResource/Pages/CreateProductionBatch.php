<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionBatchResource\Pages;

use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Production\ProductionBatchResource;
use App\Models\Masters\Product;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateProductionBatch extends CreateRecord
{
    protected static string $resource = ProductionBatchResource::class;

    protected static ?string $title = 'Open milling batch';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ProductionService::class)->open(
                Product::query()->findOrFail($data['paddy_product_id']),
                $data['line'] ?? null,
                $data['notes'] ?? null,
                CarbonImmutable::parse($data['opened_at']),
            );
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.paddy_product_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

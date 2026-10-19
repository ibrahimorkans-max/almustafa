<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production\ProductionBatchResource\Pages;

use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Production\ProductionBatchResource;
use App\Filament\Support\OffersPrintAfterCreate;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateProductionBatch extends CreateRecord
{
    use OffersPrintAfterCreate;

    protected string $printType = 'batch';

    protected static string $resource = ProductionBatchResource::class;

    protected static ?string $title = 'Create batch';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $supervisor = filled($data['supervisor_login_account_id'] ?? null) ? LoginAccount::query()->findOrFail($data['supervisor_login_account_id']) : null;
        $planned = filled($data['planned_kg'] ?? null) ? (string) $data['planned_kg'] : null;
        try {
            if ($data['via_requisition'] ?? false) {
                return app(ProductionService::class)->open(Product::query()->findOrFail($data['paddy_product_id']), $data['line'] ?? null,
                    $data['notes'] ?? null, CarbonImmutable::parse($data['opened_at']), $planned, $supervisor);
            }

            return app(ProductionService::class)->create(
                Product::query()->findOrFail($data['paddy_product_id']),
                Godown::query()->findOrFail($data['godown_id']),
                (string) $data['issue_kg'],
                CarbonImmutable::parse($data['opened_at']),
                $data['line'] ?? null,
                $planned,
                $supervisor,
                $data['notes'] ?? null,
            );
        } catch (BusinessRuleViolation $e) {
            // e.g. "Not enough Paddy PR-126 in Silo 1: 1,200 kg available, 5,000 kg requested."
            throw ValidationException::withMessages([str_contains($e->getMessage(), 'Not enough') ? 'data.issue_kg' : 'data.paddy_product_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

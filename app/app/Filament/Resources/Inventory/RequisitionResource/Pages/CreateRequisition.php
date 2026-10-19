<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\RequisitionResource\Pages;

use App\Domain\Inventory\RequisitionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Inventory\RequisitionResource;
use App\Filament\Support\OffersPrintAfterCreate;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\ProductionBatch;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRequisition extends CreateRecord
{
    use OffersPrintAfterCreate;

    protected string $printType = 'requisition';

    protected static string $resource = RequisitionResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $toBatch = $data['destination'] === 'batch' ? ProductionBatch::query()->findOrFail($data['to_batch_id']) : null;
        $toGodown = $data['destination'] === 'godown' ? Godown::query()->findOrFail($data['to_godown_id']) : null;

        try {
            return app(RequisitionService::class)->request(
                Product::query()->findOrFail($data['product_id']),
                Godown::query()->findOrFail($data['from_godown_id']),
                (string) $data['quantity_kg'],
                $toBatch,
                $toGodown,
                $data['purpose'] ?? null,
            );
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.product_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

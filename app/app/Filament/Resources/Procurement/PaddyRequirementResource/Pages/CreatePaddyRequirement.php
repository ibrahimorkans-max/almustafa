<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\PaddyRequirementResource\Pages;

use App\Domain\Procurement\RequirementService;
use App\Filament\Resources\Procurement\PaddyRequirementResource;
use App\Filament\Support\Units;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaddyRequirement extends CreateRecord
{
    protected static string $resource = PaddyRequirementResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(RequirementService::class)->create([
            'product_id' => $data['product_id'],
            'quantity_kg' => Units::maundToKg((string) $data['quantity_maund']),
            'target_rate_paise_per_maund' => ($data['target_rate_rupees'] ?? '') === '' ? null : Units::rupeesToPaise((string) $data['target_rate_rupees']),
            'quality_spec' => ['moisture_max_bp' => Units::percentToBp((string) ($data['moisture_max_percent'] ?? '17'))],
            'delivery_from' => $data['delivery_from'] ?? null,
            'delivery_to' => $data['delivery_to'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}

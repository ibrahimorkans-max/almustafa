<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\PaddyStockEntryResource\Pages;

use App\Domain\Inventory\PaddyStockEntries;
use App\Domain\Security\EntryLocation;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Inventory\PaddyStockEntryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePaddyStockEntry extends CreateRecord
{
    protected static string $resource = PaddyStockEntryResource::class;

    protected static ?string $title = 'New paddy stock entry';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        EntryLocation::hold($data);
        try {
            return app(PaddyStockEntries::class)->record(PaddyStockEntryResource::toInput($data), (bool) ($data['post'] ?? false));
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.gross_kg' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

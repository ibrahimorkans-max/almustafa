<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\GateEntryResource\Pages;

use App\Domain\Gate\GateService;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Support\Values\PakistanIds;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateGateEntry extends CreateRecord
{
    protected static string $resource = GateEntryResource::class;

    protected static ?string $title = 'Vehicle arrived';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        if (! PakistanIds::isValidVehicleNumber(PakistanIds::normalizeVehicleNumber((string) $data['registration_no']))) {
            throw ValidationException::withMessages(['data.registration_no' => 'Not a valid registration number.']);
        }

        return app(GateService::class)->arrive(array_filter($data, fn ($v) => $v !== null && $v !== ''));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

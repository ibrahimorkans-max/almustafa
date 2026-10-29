<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\GateEntryResource\Pages;

use App\Domain\Gate\GateService;
use App\Domain\Security\EntryLocation;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\Gate\GateEntry;
use App\Support\Values\PakistanIds;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
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
        EntryLocation::hold($data);
        $noPlate = (bool) ($data['no_plate'] ?? false);
        if (! $noPlate && ! PakistanIds::isValidVehicleNumber(PakistanIds::normalizeVehicleNumber((string) ($data['registration_no'] ?? '')))) {
            throw ValidationException::withMessages(['data.registration_no' => 'Not a valid registration number. No number plate? Tick "No number plate".']);
        }
        if ($noPlate) {
            unset($data['registration_no']);
        }
        try {
            return app(GateService::class)->arrive(array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== false));
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages([($noPlate ? 'data.vehicle_description' : 'data.registration_no') => $e->getMessage()]);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var GateEntry $entry */
        $entry = $this->record;

        return Notification::make()->success()->persistent()
            ->title('Token '.$entry->tokenLabel().' — '.$entry->vehicleLabel().($entry->jack_type !== null ? ' · '.$entry->jackLabel() : ''))
            ->body('Give the driver the token slip; vehicles are served in token order.')
            ->actions([
                NotificationAction::make('print80')->label('Print token (80 mm)')->button()
                    ->url(route('filament.admin.gate.token', ['id' => $entry->id, 'paper' => 'roll']), shouldOpenInNewTab: true),
                NotificationAction::make('print50')->label('Print token (50 mm)')->button()->color('gray')
                    ->url(route('filament.admin.gate.token', ['id' => $entry->id, 'paper' => 'roll50']), shouldOpenInNewTab: true),
            ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

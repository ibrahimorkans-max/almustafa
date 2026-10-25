<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\PartyResource\Pages;

use App\Filament\Resources\Masters\PartyResource;
use App\Filament\Support\PartyLogin;
use App\Models\Masters\Party;
use Filament\Resources\Pages\CreateRecord;

class CreateParty extends CreateRecord
{
    protected static string $resource = PartyResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PartyResource::mutate($data, $this->form->getRawState());
    }

    /** "Make a login and send the details" ticked on the form. */
    protected function afterCreate(): void
    {
        if (($this->form->getRawState()['make_login'] ?? false) && PartyLogin::allowed()) {
            /** @var Party $party */
            $party = $this->record;
            PartyLogin::grant($party);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

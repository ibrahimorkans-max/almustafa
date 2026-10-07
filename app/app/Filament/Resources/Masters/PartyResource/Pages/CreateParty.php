<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\PartyResource\Pages;

use App\Filament\Resources\Masters\PartyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateParty extends CreateRecord
{
    protected static string $resource = PartyResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PartyResource::mutate($data, $this->form->getRawState());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

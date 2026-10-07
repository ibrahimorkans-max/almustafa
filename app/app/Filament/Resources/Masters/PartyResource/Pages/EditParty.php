<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\PartyResource\Pages;

use App\Filament\Resources\Masters\PartyResource;
use Filament\Resources\Pages\EditRecord;

class EditParty extends EditRecord
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PartyResource::mutate($data, $this->form->getRawState());
    }
}

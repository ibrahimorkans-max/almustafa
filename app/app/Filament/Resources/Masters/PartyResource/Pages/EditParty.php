<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\PartyResource\Pages;

use App\Filament\Resources\Masters\PartyResource;
use App\Filament\Support\PartyLogin;
use Filament\Resources\Pages\EditRecord;

class EditParty extends EditRecord
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PartyLogin::action(),
            // Every entry on the party's account, like a bank statement.
            \Filament\Actions\Action::make('transactions')->label('Transactions')->icon('heroicon-o-queue-list')->color('gray')
                ->visible(fn () => \App\Filament\Pages\PartyTransactionsPage::canAccess())
                ->url(fn () => \App\Filament\Pages\PartyTransactionsPage::getUrl().'?party='.$this->record->getKey()),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PartyResource::mutate($data, $this->form->getRawState());
    }
}

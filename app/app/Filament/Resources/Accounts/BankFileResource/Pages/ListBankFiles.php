<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\BankFileResource\Pages;

use App\Domain\Accounts\BankFiles;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\BankFileResource;
use App\Models\Accounts\LedgerAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBankFiles extends ListRecords
{
    protected static string $resource = BankFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('Create bank file')->icon('heroicon-o-plus')
                ->visible(fn () => Filament::auth()->user()?->can('payment.approve') ?? false)
                ->modalDescription('Collects all released NEFT / RTGS / UPI payments from this account that are not in a file yet.')
                ->form([
                    Select::make('bank')->label('Mill bank account')->required()
                        ->options(fn () => LedgerAccount::query()->where('kind', 'bank')->where('status', 'active')->get()
                            ->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->displayName()])),
                ])
                ->action(function (array $data): void {
                    try {
                        $file = app(BankFiles::class)->create(LedgerAccount::query()->findOrFail($data['bank']));
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title("{$file->number}: {$file->payment_count} payments")->success()->send();
                }),
        ];
    }
}

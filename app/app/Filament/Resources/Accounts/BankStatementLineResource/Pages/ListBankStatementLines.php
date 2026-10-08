<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\BankStatementLineResource\Pages;

use App\Domain\Accounts\BankReconciliation;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\BankStatementLineResource;
use App\Models\Accounts\LedgerAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListBankStatementLines extends ListRecords
{
    protected static string $resource = BankStatementLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('Import statement')->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => Filament::auth()->user()?->can('ledger.post') ?? false)
                ->modalDescription('CSV with a header row: date, description, reference (optional), debit, credit. Re-importing the same rows is safe.')
                ->form([
                    Select::make('bank')->label('Mill bank account')->required()
                        ->options(fn () => LedgerAccount::query()->where('kind', 'bank')->where('status', 'active')->get()
                            ->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->displayName()])),
                    FileUpload::make('file')->label('Statement (CSV)')->required()->storeFiles(false)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->maxSize(2048),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'];
                    $csv = $file instanceof TemporaryUploadedFile ? (string) $file->get() : '';
                    try {
                        $r = app(BankReconciliation::class)->import(LedgerAccount::query()->findOrFail($data['bank']), $csv);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->success()
                        ->title("{$r['imported']} lines imported, {$r['matched']} matched automatically".($r['duplicates'] ? ", {$r['duplicates']} already imported" : ''))
                        ->send();
                }),
        ];
    }
}

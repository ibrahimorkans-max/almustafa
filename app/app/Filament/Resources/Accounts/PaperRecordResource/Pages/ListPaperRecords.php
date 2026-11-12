<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PaperRecordResource\Pages;

use App\Domain\Accounts\PaperRecords;
use App\Domain\Files\Attachments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Accounts\PaperRecordResource;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListPaperRecords extends ListRecords
{
    protected static string $resource = PaperRecordResource::class;

    protected ?string $subheading = 'Everything from the paper registers before the software: opening balances, opening stock, past purchases, sales, payments, receipts, expenses, old weighbridge slips and any other entry. Each is posted on its paper date; parties are not sent messages about them.';

    private static function canEnter(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ($me->hasPermission('ledger.post') || $me->hasPermission('stock.adjust'))
            && app(TenantContext::class)->entityId() !== null;
    }

    private static function file(mixed $file): ?TemporaryUploadedFile
    {
        if (is_array($file)) {
            $file = reset($file) ?: null;
        }

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')->label('Spreadsheet template')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print PaperRecords::template(), 'old-records-template.csv', ['Content-Type' => 'text/csv'])),

            Action::make('import')->label('Import from spreadsheet')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->visible(fn () => self::canEnter())
                ->modalHeading('Import old records from a spreadsheet')
                ->modalDescription('Fill the template in Excel (one row per entry, any kinds mixed), save it as "CSV", and choose it here. Each row is entered on its own; rows with a mistake are listed and left out, so fix them and import those rows again.')
                ->form([
                    Placeholder::make('columns')->label('Columns')->content(new HtmlString('<span class="text-sm">'
                        .e(implode(', ', PaperRecords::COLUMNS)).'.<br>Kinds: '.e(implode(', ', array_keys(PaperRecords::KINDS)))
                        .'.<br>Side: <b>receivable</b> / <b>payable</b> for a party\'s opening balance, <b>dr</b> / <b>cr</b> for an account. Dates as 31/12/2024. Parties, accounts, products and godowns by name or code; a party not yet in the system is added.</span>')),
                    FileUpload::make('file')->label('CSV file')->required()->storeFiles(false)->maxSize(10240)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel']),
                ])
                ->action(function (array $data): void {
                    $file = self::file($data['file'] ?? null);
                    try {
                        if ($file === null) {
                            throw new BusinessRuleViolation('Choose the CSV file.');
                        }
                        $result = app(PaperRecords::class)->import((string) $file->get());
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $errors = $result['errors'];
                    $body = $errors === [] ? null : 'Rows left out — '.implode('; ', array_map(fn ($e) => "row {$e['row']}: {$e['error']}", array_slice($errors, 0, 15)))
                        .(count($errors) > 15 ? sprintf('; and %d more.', count($errors) - 15) : '.');
                    Notification::make()->title(sprintf('%d record(s) entered%s (import %s).', $result['entered'], $errors === [] ? '' : ', '.count($errors).' row(s) with mistakes', $result['batch']))
                        ->body($body)->{$errors === [] ? 'success' : 'warning'}()->persistent()->send();
                }),

            Action::make('add')->label('Add old record')->icon('heroicon-o-plus')
                ->visible(fn () => self::canEnter())
                ->modalHeading('Add a record from the paper registers')->modalWidth('3xl')
                ->form(PaperRecordResource::entryForm())
                ->extraModalFooterActions(fn (Action $action) => [$action->makeModalSubmitAction('addAnother', ['another' => true])->label('Save and add another')])
                ->action(function (array $data, array $arguments, Action $action, Form $form): void {
                    $photo = self::file($data['photo'] ?? null);
                    unset($data['photo']);
                    try {
                        $record = app(PaperRecords::class)->record($data);
                        if ($photo !== null) {
                            app(Attachments::class)->attach($record, 'paper_page', (string) $photo->get());
                        }
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->success()->title(PaperRecords::KINDS[$record->kind].' entered'
                        .($record->voucher ? ' — voucher '.$record->voucher->number : '').'.')->send();
                    if ($arguments['another'] ?? false) {
                        // The next line of the same page: same kind, date, register and cash/bank; the dialog stays open.
                        $form->fill(array_intersect_key($data, array_flip(['kind', 'date', 'book_ref', 'cash_bank_id', 'godown_id'])));
                        $action->halt();
                    }
                }),
        ];
    }
}

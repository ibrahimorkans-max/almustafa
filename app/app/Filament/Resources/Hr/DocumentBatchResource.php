<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Documents\Engine\DocumentBatches;
use App\Domain\Documents\Engine\DocumentTypes;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\DocumentBatchResource\Pages;
use App\Filament\Support\DocumentActions;
use App\Models\Documents\DocumentBatch;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Bulk generation on the queue: progress, failures with reasons, retry, one PDF to print and a ZIP of the originals. */
class DocumentBatchResource extends Resource
{
    protected static ?string $model = DocumentBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Bulk documents';

    protected static ?string $modelLabel = 'bulk generation';

    protected static ?string $slug = 'hr/document-batches';

    protected static ?int $navigationSort = 52;

    public static function canViewAny(): bool
    {
        return DocumentActions::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $download = fn (string $what) => function (DocumentBatch $record) use ($what) {
            try {
                return DocumentActions::stream(app(DocumentBatches::class)->download($record, $what));
            } catch (BusinessRuleViolation $e) {
                Notification::make()->title($e->getMessage())->danger()->send();

                return null;
            }
        };

        return $table
            ->poll('5s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Started')->dateTime('d M Y H:i', 'Asia/Karachi'),
                TextColumn::make('label'),
                TextColumn::make('doc_type')->label('Document')->formatStateUsing(fn (string $state) => DocumentTypes::options()[$state] ?? $state),
                TextColumn::make('progress')->state(fn (DocumentBatch $r) => "{$r->done_count} / {$r->total} ({$r->percent()} %)"),
                TextColumn::make('failed_count')->label('Failed')->state(fn (DocumentBatch $r) => count($r->failed ?? []))
                    ->color(fn (int $state) => $state > 0 ? 'danger' : null),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    DocumentBatch::DONE => 'success', DocumentBatch::FAILED => 'danger', DocumentBatch::RUNNING => 'info', default => 'gray',
                }),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('pdf')->label('PDF to print')->icon('heroicon-o-printer')
                        ->visible(fn (DocumentBatch $r) => $r->status === DocumentBatch::DONE && $r->pdf_path !== null)->action($download('pdf')),
                    Action::make('zip')->label('ZIP of originals')->icon('heroicon-o-archive-box-arrow-down')
                        ->visible(fn (DocumentBatch $r) => $r->status === DocumentBatch::DONE && $r->zip_path !== null)->action($download('zip')),
                    Action::make('failures')->label('Why some failed')->icon('heroicon-o-exclamation-triangle')->color('danger')
                        ->visible(fn (DocumentBatch $r) => ($r->failed ?? []) !== [] || $r->error !== null)
                        ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalContent(fn (DocumentBatch $record) => view('filament.documents.batch-failures', ['batch' => $record])),
                    Action::make('retry')->label('Retry the failed ones')->icon('heroicon-o-arrow-path')
                        ->visible(fn (DocumentBatch $r) => ($r->failed ?? []) !== [] && $r->status === DocumentBatch::DONE)
                        ->action(function (DocumentBatch $record): void {
                            try {
                                app(DocumentBatches::class)->retryFailed($record);
                                Notification::make()->title('Retry started.')->success()->send();
                            } catch (BusinessRuleViolation $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocumentBatches::route('/')];
    }
}

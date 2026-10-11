<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Documents\Engine\DocumentService;
use App\Domain\Documents\Engine\DocumentTypes;
use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Support\DocumentActions;
use App\Models\Documents\GeneratedDocument;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Employee self-service: my payslips, receipts and certificates, and nobody else's. */
class MyDocumentsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'My documents';

    protected static ?string $title = 'My documents';

    protected static ?string $slug = 'my-documents';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.my-documents';

    public static function canAccess(): bool
    {
        return self::employeeId() !== null;
    }

    public static function employeeId(): ?string
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount ? app(AttendanceService::class)->employeeFor($user)?->id : null;
    }

    public function table(Table $table): Table
    {
        $mine = self::employeeId();

        return $table
            // Only this login's employee, and only the current revision of each document.
            ->query(GeneratedDocument::query()->where('employee_id', $mine ?? '00000000-0000-0000-0000-000000000000')
                ->whereNotIn('id', GeneratedDocument::query()->whereNotNull('supersedes_id')->select('supersedes_id')))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription('Payslips appear here once the month\'s payroll is finalized.')
            ->columns([
                TextColumn::make('created_at')->label('Issued')->dateTime('d M Y', 'Asia/Karachi'),
                TextColumn::make('doc_type')->label('Document')->formatStateUsing(fn (string $state) => DocumentTypes::options()[$state] ?? $state),
                TextColumn::make('period')->label('Month')->state(fn (GeneratedDocument $r) => isset($r->payload['period']) ? CarbonImmutable::createFromFormat('!Y-m', (string) $r->payload['period'])->format('F Y') : '—'),
                TextColumn::make('number')->formatStateUsing(fn (GeneratedDocument $record) => $record->displayNumber()),
            ])
            ->actions([
                Action::make('download')->icon('heroicon-o-arrow-down-tray')
                    ->action(function (GeneratedDocument $record) {
                        try {
                            return DocumentActions::stream(app(DocumentService::class)->original($record));
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return null;
                        }
                    }),
            ]);
    }
}

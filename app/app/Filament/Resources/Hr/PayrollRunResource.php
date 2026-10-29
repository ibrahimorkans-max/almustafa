<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\PayrollRunResource\Pages;
use App\Filament\Resources\Hr\PayrollRunResource\RelationManagers\ItemsRelationManager;
use App\Filament\Support\Units;
use App\Models\Hr\PayrollBankFile;
use App\Models\Hr\PayrollRun;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The monthly payroll: open → lock attendance → calculate → approve (second person) → finalize → pay. */
class PayrollRunResource extends Resource
{
    protected static ?string $model = PayrollRun::class;

    protected static ?string $recordTitleAttribute = 'number';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Payroll';

    protected static ?string $modelLabel = 'payroll';

    protected static ?string $slug = 'hr/payroll';

    protected static ?int $navigationSort = 40;

    public static function allows(string $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission($permission);
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            PayrollRun::DRAFT => 'gray', PayrollRun::LOCKED => 'info', PayrollRun::CALCULATED => 'warning', PayrollRun::APPROVED => 'primary', default => 'success',
        };
    }

    public static function table(Table $table): Table
    {
        $total = fn (string $key) => fn (PayrollRun $r) => isset($r->totals[$key]) ? Units::paiseToRupees((int) $r->totals[$key]) : '—';

        return $table
            ->columns([
                TextColumn::make('month')->sortable(),
                TextColumn::make('number')->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => PayrollRun::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('employees')->label('Staff')->state(fn (PayrollRun $r) => $r->totals['employees'] ?? '—'),
                TextColumn::make('gross')->label('Gross')->alignEnd()->state($total('gross_paise')),
                TextColumn::make('net')->label('Net pay')->alignEnd()->state($total('net_paise')),
                TextColumn::make('warnings')->state(fn (PayrollRun $r) => $r->totals['warnings'] ?? '—')
                    ->color(fn ($state) => is_int($state) && $state > 0 ? 'warning' : null),
            ])
            ->defaultSort('month', 'desc')
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $total = fn (string $key) => fn (PayrollRun $r) => isset($r->totals[$key]) ? Units::paiseToRupees((int) $r->totals[$key]) : '—';

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('month'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => PayrollRun::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('calculation_no')->label('Calculation')->placeholder('—'),
                TextEntry::make('employees')->label('Staff')->state(fn (PayrollRun $r) => $r->totals['employees'] ?? '—'),
                TextEntry::make('gross')->label('Gross')->state($total('gross_paise')),
                TextEntry::make('deductions')->label('Deductions')->state($total('deductions_paise')),
                TextEntry::make('net')->label('Net pay')->state($total('net_paise')),
                TextEntry::make('employer')->label('Employer contributions')->state($total('employer_paise')),
                TextEntry::make('warnings')->state(fn (PayrollRun $r) => $r->totals['warnings'] ?? '—'),
                TextEntry::make('approved_at')->label('Approved')->dateTime('d M Y H:i', 'Asia/Karachi')->placeholder('—'),
                TextEntry::make('finalized_at')->label('Finalized')->dateTime('d M Y H:i', 'Asia/Karachi')->placeholder('—'),
            ]),
            Section::make('Bank transfer files')->visible(fn (PayrollRun $r) => $r->status === PayrollRun::FINALIZED)->schema([
                RepeatableEntry::make('bank_files')->hiddenLabel()->columns(4)->placeholder('None yet.')
                    ->state(fn (PayrollRun $r) => PayrollBankFile::query()->where('payroll_run_id', $r->id)->orderBy('created_at')->get()
                        ->map(fn (PayrollBankFile $f) => ['number' => $f->number, 'format' => $f->format, 'count' => $f->payment_count, 'total' => Units::paiseToRupees($f->total_paise)])->all())
                    ->schema([TextEntry::make('number'), TextEntry::make('format'), TextEntry::make('count')->label('Payments'), TextEntry::make('total')->label('Total')]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollRuns::route('/'),
            'view' => Pages\ViewPayrollRun::route('/{record}'),
        ];
    }
}

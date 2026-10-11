<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Hr\Payroll\PieceWorkService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\PieceWorkEntryResource\Pages;
use App\Models\Gate\GateEntry;
use App\Models\Hr\Employee;
use App\Models\Hr\PieceWorkEntry;
use App\Models\Production\ProductionBatch;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Piece work (bags loaded / unloaded, maund milled) behind piece-rate wages. Append-only. */
class PieceWorkEntryResource extends Resource
{
    protected static ?string $model = PieceWorkEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Piece work';

    protected static ?string $modelLabel = 'piece-work entry';

    protected static ?string $slug = 'hr/piece-work';

    protected static ?int $navigationSort = 31;

    /** @return array<string, string> */
    public static function employeeOptions(): array
    {
        return Employee::query()->with('person')->where('status', '!=', Employee::EXITED)->orderBy('code')->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}"])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee.person'))
            ->columns([
                TextColumn::make('work_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('employee.code')->label('Employee')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name'),
                TextColumn::make('quantity')->alignEnd()->color(fn (PieceWorkEntry $r) => bccomp((string) $r->quantity, '0', 3) < 0 ? 'danger' : null),
                TextColumn::make('unit')->formatStateUsing(fn (string $state) => PieceWorkEntry::UNITS[$state] ?? $state),
                TextColumn::make('source_type')->label('From')->badge()
                    ->formatStateUsing(fn (string $state) => ['manual' => 'Entered', 'gate_entry' => 'Gate entry', 'production_batch' => 'Milling batch', 'correction' => 'Correction'][$state] ?? $state),
                TextColumn::make('note')->wrap()->placeholder('—'),
            ])
            ->defaultSort('work_date', 'desc')
            ->filters([
                SelectFilter::make('unit')->options(PieceWorkEntry::UNITS),
                SelectFilter::make('employee_id')->label('Employee')->options(fn () => self::employeeOptions())->searchable(),
            ])
            ->headerActions(self::recordActions())
            ->actions([
                Action::make('correct')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (PieceWorkEntry $r) => $r->source_type !== 'correction' && bccomp((string) $r->quantity, '0', 3) > 0)
                    ->modalDescription('Adds a negative entry that cancels this one. Both stay in the list.')
                    ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                    ->action(fn (PieceWorkEntry $record, array $data) => self::attempt(fn () => app(PieceWorkService::class)->correct($record, $data['reason']), 'Correction recorded.')),
            ]);
    }

    /** @return list<Action> */
    public static function recordActions(): array
    {
        $gang = fn () => Select::make('employees')->label('Who did it')->multiple()->required()->searchable()->options(fn () => self::employeeOptions());

        return [
            Action::make('record')->label('Record work')->icon('heroicon-o-plus')
                ->form([
                    $gang(),
                    DatePicker::make('date')->required()->default(now('Asia/Karachi'))->maxDate(now('Asia/Karachi')),
                    Select::make('unit')->options(PieceWorkEntry::UNITS)->required()->default('bag'),
                    TextInput::make('quantity')->label('Total quantity (shared equally)')->required()->regex('/^\d{1,9}(\.\d{1,3})?$/'),
                    Textarea::make('note')->maxLength(500),
                ])
                ->action(fn (array $data) => self::attempt(fn () => app(PieceWorkService::class)->record(
                    array_values($data['employees']), CarbonImmutable::parse($data['date'], 'Asia/Karachi'), $data['unit'], (string) $data['quantity'], 'manual', null, $data['note'] ?? null,
                ), 'Work recorded.')),
            Action::make('fromGate')->label('From a gate entry')->icon('heroicon-o-truck')->color('gray')
                ->form([
                    Select::make('gate_entry_id')->label('Vehicle (bags)')->required()->searchable()
                        ->options(fn () => GateEntry::query()->where('bag_count', '>', 0)->latest('arrived_at')->limit(200)->get()
                            ->mapWithKeys(fn (GateEntry $g) => [$g->id => "{$g->number} · {$g->registration_no} · {$g->bag_count} bags · ".($g->direction === 'inbound' ? 'unloaded' : 'loaded')])->all()),
                    $gang(),
                ])
                ->action(fn (array $data) => self::attempt(fn () => app(PieceWorkService::class)->fromGateEntry(
                    GateEntry::query()->findOrFail($data['gate_entry_id']), array_values($data['employees']),
                ), 'Bags recorded.')),
            Action::make('fromBatch')->label('From a milling batch')->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->form([
                    Select::make('batch_id')->label('Batch')->required()->searchable()
                        ->options(fn () => ProductionBatch::query()->latest('opened_at')->limit(200)->get()
                            ->mapWithKeys(fn (ProductionBatch $b) => [$b->id => "{$b->number} · ".bcdiv((string) $b->input_kg, '40', 1).' maund'])->all()),
                    $gang(),
                ])
                ->action(fn (array $data) => self::attempt(fn () => app(PieceWorkService::class)->fromBatch(
                    ProductionBatch::query()->findOrFail($data['batch_id']), array_values($data['employees']),
                ), 'Milling recorded.')),
        ];
    }

    public static function attempt(callable $do, string $ok): void
    {
        try {
            $do();
            Notification::make()->title($ok)->success()->send();
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPieceWorkEntries::route('/')];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\RelationManagers;

use App\Filament\Support\Units;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use App\Models\Hr\SalaryStructureLine;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Salary history: every version, newest first. Only for holders of hr.salary.view. */
class SalaryStructuresRelationManager extends RelationManager
{
    protected static string $relationship = 'salaryStructures';

    protected static ?string $title = 'Salary history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('hr.salary.view');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $current = null;
        $state = function (SalaryStructure $r) use (&$current): string {
            $current ??= $r->employee->salaryOn(now())->id ?? '';
            if (SalaryStructure::query()->where('supersedes_id', $r->id)->exists()) {
                return 'replaced';
            }

            return match (true) {
                $r->id === $current => 'in force',
                $r->effective_from->isFuture() => 'from '.$r->effective_from->format('d M Y'),
                default => 'earlier',
            };
        };

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lines.component'))
            ->columns([
                TextColumn::make('effective_from')->date('d M Y')->sortable(),
                TextColumn::make('pay_basis')->label('Basis')->formatStateUsing(fn (string $state) => SalaryStructure::BASES[$state] ?? $state),
                TextColumn::make('earnings')->label('Earnings')->alignEnd()
                    ->state(fn (SalaryStructure $r) => Units::paiseToRupees($r->totalPaise(SalaryComponent::EARNING))),
                TextColumn::make('deductions')->label('Fixed deductions')->alignEnd()
                    ->state(fn (SalaryStructure $r) => Units::paiseToRupees($r->totalPaise(SalaryComponent::DEDUCTION))),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('state')->badge()
                    ->state($state)
                    ->color(fn (string $state) => match ($state) {
                        'in force' => 'success', 'replaced', 'earlier' => 'gray', default => 'info'
                    }),
                TextColumn::make('created_at')->label('Recorded')->dateTime('d M Y H:i'),
            ])
            ->defaultSort('effective_from', 'desc')
            ->actions([ViewAction::make()->infolist(fn (Infolist $infolist) => $infolist->schema([
                TextEntry::make('lines_view')->label('Pay heads')->listWithLineBreaks()
                    ->state(fn (SalaryStructure $r) => $r->lines->sortBy(fn (SalaryStructureLine $l) => $l->component->sort)
                        ->map(fn (SalaryStructureLine $l) => sprintf('%s (%s): %s%s', $l->component->name, $l->component->kind,
                            Units::paiseToRupees($l->amount_paise), $l->unit ? ' per '.$l->unit : ''))->values()->all()),
                TextEntry::make('reason'),
            ]))]);
    }
}

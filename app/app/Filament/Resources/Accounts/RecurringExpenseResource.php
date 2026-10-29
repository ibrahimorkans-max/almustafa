<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Filament\Resources\Accounts\RecurringExpenseResource\Pages;
use App\Filament\Support\Units;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\RecurringExpense;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Monthly expenses that draft themselves (rent, electricity, guards …); accounts check and post each draft. */
class RecurringExpenseResource extends Resource
{
    protected static ?string $model = RecurringExpense::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Recurring expenses';

    protected static ?string $modelLabel = 'recurring expense';

    protected static ?string $slug = 'recurring-expenses';

    protected static ?int $navigationSort = 80;

    public static function form(Form $form): Form
    {
        $month = '/^\d{4}-(0[1-9]|1[0-2])$/';

        return $form->columns(2)->schema([
            TextInput::make('name')->required()->maxLength(120)->placeholder('e.g. Godown rent'),
            Select::make('expense_category_id')->label('Category')->required()->searchable()
                ->options(fn () => ExpenseCategory::query()->where('status', 'active')->where('kind', 'expense')->orderBy('name')->pluck('name', 'id')),
            TextInput::make('amount')->label('Usual amount (Rs)')->required()->regex(Units::DECIMAL_2)
                ->helperText('Each month\'s draft can be corrected before posting.'),
            TextInput::make('day_of_month')->label('Day of the month')->required()->integer()->minValue(1)->maxValue(28)->default(1),
            TextInput::make('starts_month')->label('First month')->required()->regex($month)->default(fn () => now('Asia/Karachi')->format('Y-m'))->placeholder('2026-10'),
            TextInput::make('ends_month')->label('Last month')->regex($month)->placeholder('open-ended'),
            Radio::make('via')->label('Paid from')->inline()->required()->default('cash')->live()->options(ExpenseResource::VIA)
                ->afterStateUpdated(fn (Set $set) => $set('pay_from_ledger_id', null)),
            ExpenseResource::ledgerSelect('pay_from_ledger_id'),
            Select::make('party_id')->label('Party (landlord, utility …)')->searchable()->options(fn () => ExpenseResource::parties())
                ->visible(fn (Get $get) => $get('via') === 'party')->required(fn (Get $get) => $get('via') === 'party'),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'payFrom', 'party']))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('amount_paise')->label('Amount')->alignEnd()->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state)),
                TextColumn::make('day_of_month')->label('Day')->alignEnd(),
                TextColumn::make('via')->label('Paid')->state(fn (RecurringExpense $r) => $r->payFrom->name ?? ($r->party !== null ? 'On credit: '.$r->party->name : '—')),
                TextColumn::make('period')->state(fn (RecurringExpense $r) => $r->starts_month.' → '.($r->ends_month ?? 'open')),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('name')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecurringExpenses::route('/'),
            'create' => Pages\CreateRecurringExpense::route('/create'),
            'edit' => Pages\EditRecurringExpense::route('/{record}/edit'),
        ];
    }
}

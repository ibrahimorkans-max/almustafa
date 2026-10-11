<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Filament\Resources\Accounts\ExpenseCategoryResource\Pages;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Expense and income categories, each posted to one ledger; staff claims choose from the claimable ones. */
class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Expense categories';

    protected static ?string $modelLabel = 'expense category';

    protected static ?string $slug = 'expense-categories';

    protected static ?int $navigationSort = 45;

    public const KINDS = ['expense' => 'Expense', 'income' => 'Income'];

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Za-z][A-Za-z0-9_]{1,19}$/')->helperText('2–20 letters, digits or _'),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('kind')->options(self::KINDS)->default('expense')->required()->live(),
            Select::make('ledger_account_id')->label('Ledger')->required()->searchable()
                ->options(fn (Get $get) => LedgerAccount::query()->where('status', 'active')
                    ->whereHas('group', fn (Builder $query) => $query->where('nature', $get('kind') ?? 'expense'))
                    ->orderBy('name')->get()->mapWithKeys(fn (LedgerAccount $l) => [$l->id => "{$l->code} {$l->name}"])->all()),
            Toggle::make('claimable')->label('Staff may claim it')->default(true)->visible(fn (Get $get) => $get('kind') === 'expense'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('ledger'))
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => self::KINDS[$state] ?? $state),
                TextColumn::make('ledger.name')->label('Ledger'),
                IconColumn::make('claimable')->label('Claimable')->boolean(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('code')
            ->filters([SelectFilter::make('kind')->options(self::KINDS)]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenseCategories::route('/'),
            'create' => Pages\CreateExpenseCategory::route('/create'),
        ];
    }
}

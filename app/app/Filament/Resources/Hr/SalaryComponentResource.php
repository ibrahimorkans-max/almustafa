<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\SalaryComponentResource\Pages;
use App\Models\Hr\SalaryComponent;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SalaryComponentResource extends Resource
{
    protected static ?string $model = SalaryComponent::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Pay heads';

    protected static ?string $modelLabel = 'pay head';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Z0-9_]+$/')
                ->disabledOn('edit')->helperText('Upper case, e.g. NIGHT. Fixed once created.')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => HrScope::inEntity($rule)),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('kind')->options(['earning' => 'Earning', 'deduction' => 'Deduction'])->required()->disabledOn('edit'),
            Toggle::make('is_statutory')->label('Statutory (PF, ESI, PT, TDS)'),
            TextInput::make('sort')->numeric()->default(100)->required(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->badge()->color(fn (string $state) => $state === 'earning' ? 'success' : 'warning'),
                IconColumn::make('is_statutory')->label('Statutory')->boolean(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSalaryComponents::route('/')];
    }
}

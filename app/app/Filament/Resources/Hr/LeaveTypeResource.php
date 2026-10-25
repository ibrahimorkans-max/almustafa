<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\LeaveTypeResource\Pages;
use App\Models\Hr\LeaveType;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeaveTypeResource extends Resource
{
    protected static ?string $model = LeaveType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?int $navigationSort = 64;

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Placeholder::make('note')->hiddenLabel()->columnSpanFull()
                ->content('Quotas differ by state (Shops & Establishments Act, Factories Act). The owner or accountant must check them before relying on them.'),
            TextInput::make('code')->required()->maxLength(10)->regex('/^[A-Z0-9]+$/')->disabledOn('edit')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => HrScope::inEntity($rule)),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            Toggle::make('paid')->default(true)->disabledOn('edit')->helperText('Fixed once created: pay already calculated depends on it.'),
            TextInput::make('annual_days')->label('Days per year')->required()->numeric()->minValue(0)->maxValue(366)->default(0),
            Toggle::make('half_day_allowed')->label('Half days allowed')->default(true),
            Toggle::make('carry_forward')->label('Carry forward at year end'),
            TextInput::make('max_carry_days')->label('Carry forward at most')->numeric()->minValue(0)->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name'),
                IconColumn::make('paid')->boolean(),
                TextColumn::make('annual_days')->label('Days / year'),
                TextColumn::make('carry')->label('Carry forward')
                    ->state(fn (LeaveType $r) => $r->carry_forward ? "up to {$r->max_carry_days}" : 'no'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('code')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLeaveTypes::route('/')];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\HolidayResource\Pages;
use App\Models\Hr\Holiday;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?int $navigationSort = 63;

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            DatePicker::make('date')->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => HrScope::inEntity($rule)),
            TextInput::make('name')->required()->maxLength(120)->placeholder('Diwali'),
            Select::make('kind')->options(Holiday::KINDS)->required()->default('festival'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->formatStateUsing(fn (string $state) => Holiday::KINDS[$state] ?? $state)->badge(),
            ])
            ->defaultSort('date')
            ->filters([Filter::make('upcoming')->label('From today')->toggle()->default()
                ->query(fn (Builder $query) => $query->where('date', '>=', now('Asia/Karachi')->toDateString()))])
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageHolidays::route('/')];
    }
}

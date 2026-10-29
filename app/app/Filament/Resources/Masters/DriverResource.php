<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\DriverResource\Pages;
use App\Models\Masters\Driver;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DriverResource extends Resource
{
    use MasterResourceHelpers;

    protected static ?string $model = Driver::class;

    protected static ?string $recordTitleAttribute = 'name';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone'];
    }

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('phone')->tel()->maxLength(16),
            TextInput::make('licence_no')->label('Driving licence no.')->maxLength(30),
            DatePicker::make('licence_valid_until')->label('Licence valid until'),
            Select::make('transporter_party_id')->label('Transporter')
                ->relationship('transporter', 'name', fn ($query) => $query->where('is_transporter', true))
                ->searchable()->preload(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('phone')->searchable(),
                TextColumn::make('licence_no')->label('Licence')->placeholder('—'),
                TextColumn::make('licence_valid_until')->label('Valid until')->date()
                    ->color(fn (Driver $r) => $r->licence_valid_until?->isPast() ? 'danger' : null)->placeholder('—'),
                TextColumn::make('transporter.name')->placeholder('—'),
                TextColumn::make('status')->badge(),
                static::updatedColumn(),
            ])
            ->defaultSort('name')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDrivers::route('/')];
    }
}

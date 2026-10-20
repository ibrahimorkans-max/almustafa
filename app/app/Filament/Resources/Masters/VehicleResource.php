<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\VehicleResource\Pages;
use App\Models\Masters\Vehicle;
use App\Support\Values\PakistanIds;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VehicleResource extends Resource
{
    use MasterResourceHelpers;

    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('registration_no')->label('Registration no.')->required()->maxLength(20)
                ->placeholder('MNA-21-1234')
                ->dehydrateStateUsing(fn (string $state) => PakistanIds::normalizeVehicleNumber($state))
                ->rule(fn (?Vehicle $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    $normalized = PakistanIds::normalizeVehicleNumber((string) $value);
                    if (! PakistanIds::isValidVehicleNumber($normalized)) {
                        $fail('Not a valid Pakistani registration number (e.g. MNA-21-1234, LES-1234).');

                        return;
                    }
                    $taken = Vehicle::query()->withArchived()->where('registration_no', $normalized)
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();
                    if ($taken) {
                        $fail('This vehicle is already registered.');
                    }
                }),
            Select::make('vehicle_type')->options(Vehicle::LABELS)->default('truck')->required(),
            Select::make('owner_party_id')->label('Owner')->relationship('owner', 'name')->searchable()->preload(),
            Select::make('default_driver_id')->label('Usual driver')->relationship('defaultDriver', 'name')->searchable()->preload(),
            TextInput::make('capacity_kg')->label('Capacity (kg)')->regex('/^\d{1,9}(\.\d{1,3})?$/'),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            Toggle::make('is_blocked')->label('Blocked (boom stays closed)')->live(),
            TextInput::make('blocked_reason')->required(fn (Get $get) => (bool) $get('is_blocked'))
                ->visible(fn (Get $get) => (bool) $get('is_blocked'))->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration_no')->label('Reg. no.')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('vehicle_type')->badge()->formatStateUsing(fn (?string $state) => Vehicle::typeLabel($state)),
                TextColumn::make('owner.name')->placeholder('—'),
                TextColumn::make('defaultDriver.name')->label('Driver')->placeholder('—'),
                IconColumn::make('is_blocked')->label('Blocked')->boolean()->trueColor('danger')->falseColor('gray')
                    ->tooltip(fn (Vehicle $r) => $r->blocked_reason),
                static::updatedColumn(),
            ])
            ->defaultSort('registration_no')
            ->filters([TernaryFilter::make('is_blocked')->label('Blocked')])
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageVehicles::route('/')];
    }
}

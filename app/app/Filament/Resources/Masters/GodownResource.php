<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\GodownResource\Pages;
use App\Models\Masters\Godown;
use App\Support\Values\Weight;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GodownResource extends Resource
{
    use MasterResourceHelpers;

    protected static ?string $model = Godown::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Godowns / silos';

    protected static ?int $navigationSort = 60;

    public static function form(Form $form): Form
    {
        return $form->schema([
            static::codeInput('godowns'),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('type')->options(array_combine(Godown::TYPES, array_map('ucfirst', Godown::TYPES)))->default('godown')->required(),
            TextInput::make('capacity_kg')->label('Capacity (kg)')->regex('/^\d{1,12}(\.\d{1,3})?$/')
                ->helperText('Stored in kilograms. 1 maund = 40 kg, 1 ton = 1000 kg.'),
            Textarea::make('address')->rows(2)->maxLength(500),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('capacity_kg')->label('Capacity (ton)')
                    ->formatStateUsing(fn (?string $state) => $state === null ? '—' : Weight::kg($state)->in('ton')),
                TextColumn::make('status')->badge(),
                static::updatedColumn(),
            ])
            ->defaultSort('code')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGodowns::route('/')];
    }
}

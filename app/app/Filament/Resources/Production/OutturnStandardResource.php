<?php

declare(strict_types=1);

namespace App\Filament\Resources\Production;

use App\Filament\Resources\Production\OutturnStandardResource\Pages;
use App\Filament\Support\Units;
use App\Models\Production\OutturnStandard;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Expected outturn per paddy variety; a batch outside tolerance raises an alert at close. */
class OutturnStandardResource extends Resource
{
    protected static ?string $model = OutturnStandard::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Outturn standards';

    protected static ?string $slug = 'outturn-standards';

    protected static ?int $navigationSort = 70;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('paddy_product_id')->label('Paddy')->required()->preload()
                ->relationship('paddy', 'name', fn (Builder $query) => $query->where('category', 'paddy')),
            Select::make('output_product_id')->label('Output')->required()->preload()
                ->relationship('output', 'name', fn (Builder $query) => $query->whereIn('category', ['rice', 'by_product'])),
            // Stored as basis points, edited as percent.
            TextInput::make('expected_bp')->label('Expected share of paddy (%)')->required()->regex('/^\d{1,2}(\.\d{1,2})?$/')
                ->formatStateUsing(fn ($state) => $state === null ? null : bcdiv((string) $state, '100', 2))
                ->dehydrateStateUsing(fn (string $state) => Units::percentToBp($state)),
            TextInput::make('tolerance_bp')->label('Tolerance ± (%)')->required()->regex('/^\d{1,2}(\.\d{1,2})?$/')->default('2')
                ->formatStateUsing(fn ($state) => $state === null ? '2' : bcdiv((string) $state, '100', 2))
                ->dehydrateStateUsing(fn (string $state) => Units::percentToBp($state)),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['paddy', 'output']))
            ->columns([
                TextColumn::make('paddy.name')->label('Paddy'),
                TextColumn::make('output.name')->label('Output'),
                TextColumn::make('expected_bp')->label('Expected')->formatStateUsing(fn (int $state) => Units::bpToPercent($state)),
                TextColumn::make('tolerance_bp')->label('Tolerance ±')->formatStateUsing(fn (int $state) => Units::bpToPercent($state)),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('paddy_product_id')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageOutturnStandards::route('/')];
    }
}

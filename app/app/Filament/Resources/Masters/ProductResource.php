<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\ProductResource\Pages;
use App\Models\Masters\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    use MasterResourceHelpers;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 40;

    /** @return array<string, string> */
    public static function categoryOptions(): array
    {
        return ['paddy' => 'Paddy', 'rice' => 'Rice', 'by_product' => 'By-product (husk, bran, broken)', 'packing' => 'Packing material', 'consumable' => 'Consumable', 'other' => 'Other'];
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            static::codeInput('products', 30),
            TextInput::make('name')->required()->maxLength(160),
            Select::make('category')->options(static::categoryOptions())->required(),
            TextInput::make('variety')->maxLength(60)->placeholder('1121, PR-126, Sharbati …'),
            TextInput::make('hsn_code')->label('HSN code')->regex('/^\d{4}(\d{2}){0,2}$/')->placeholder('1006'),
            Select::make('tax_rate_id')->label('GST rate')->relationship('taxRate', 'name')->preload(),
            Select::make('brand_id')->label('Brand')->relationship('brand', 'name')->preload(),
            Select::make('sale_unit')->options(['kg' => 'Kilogram', 'quintal' => 'Quintal (100 kg)', 'ton' => 'Ton (1000 kg)', 'bag' => 'Bag'])
                ->default('kg')->required()->live()->helperText('Stock is always kept in kilograms.'),
            TextInput::make('bag_weight_kg')->label('Bag weight (kg)')->regex('/^\d{1,6}(\.\d{1,3})?$/')
                ->required(fn (Get $get) => $get('sale_unit') === 'bag')->visible(fn (Get $get) => $get('sale_unit') === 'bag'),
            Toggle::make('is_stock_item')->label('Track stock')->default(true),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('category')->badge(),
                TextColumn::make('variety')->placeholder('—'),
                TextColumn::make('hsn_code')->label('HSN')->placeholder('—'),
                TextColumn::make('taxRate.name')->label('GST')->placeholder('—'),
                TextColumn::make('brand.name')->placeholder('—'),
                TextColumn::make('sale_unit'),
                static::updatedColumn(),
            ])
            ->defaultSort('code')
            ->filters([SelectFilter::make('category')->options(static::categoryOptions())])
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageProducts::route('/')];
    }
}

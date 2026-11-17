<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\ProductResource\Pages;
use App\Filament\Support\Units;
use App\Models\Masters\PaddyVariety;
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
    use \App\Filament\Concerns\TranslatesResourceLabels, MasterResourceHelpers {
        MasterResourceHelpers::getNavigationGroup insteadof \App\Filament\Concerns\TranslatesResourceLabels;
    }

    protected static ?string $model = Product::class;

    protected static ?string $recordTitleAttribute = 'name';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
    }

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
            static::codeInput('products', 30, automatic: true),
            TextInput::make('name')->required()->maxLength(160),
            Select::make('category')->options(static::categoryOptions())->required()->live(),
            Select::make('paddy_variety_id')->label(__('Paddy variety'))->options(fn () => PaddyVariety::options())->searchable()
                ->visible(fn (Get $get) => $get('category') === 'paddy')
                ->createOptionForm(PaddyVarietyResource::fields())
                ->createOptionUsing(fn (array $data) => PaddyVarietyResource::store($data)->id),
            TextInput::make('variety')->label(fn (Get $get) => $get('category') === 'paddy' ? 'Variety (free text, older records)' : 'Variety')
                ->maxLength(60)->placeholder(__('1121, PR-126, Sharbati …')),
            TextInput::make('pct_code')->label(__('PCT / HS code'))->regex('/^\d{4}(\.\d{2,4})?$/')->placeholder('1006.3090')
                ->helperText(__('Pakistan Customs Tariff heading, printed on the invoice and the sales-tax return.')),
            Select::make('tax_rate_id')->label(__('Sales tax rate'))->relationship('taxRate', 'name')->preload(),
            Select::make('brand_id')->label(__('Brand'))->relationship('brand', 'name')->preload(),
            Select::make('sale_unit')->options(['kg' => __('Kilogram'), 'maund' => __('Maund (40 kg)'), 'quintal' => __('Quintal (100 kg)'), 'ton' => __('Ton (1000 kg)'), 'bag' => __('Bag')])
                ->default('kg')->required()->live()->helperText(__('Stock is always kept in kilograms.')),
            TextInput::make('bag_weight_kg')->label(__('Bag weight (kg)'))->regex('/^\d{1,6}(\.\d{1,3})?$/')
                ->required(fn (Get $get) => $get('sale_unit') === 'bag')->visible(fn (Get $get) => $get('sale_unit') === 'bag'),
            Toggle::make('is_stock_item')->label(__('Track stock'))->default(true),
            // Cost information: only accounts sees and sets it.
            TextInput::make('valuation_paise_per_kg')->label(__('By-product valuation (Rs per kg)'))
                ->helperText(__('Realisable value used to cost by-products when a milling batch closes; the rest of the cost goes to rice.'))
                ->regex(Units::DECIMAL_2)
                ->formatStateUsing(fn ($state) => $state === null ? null : bcdiv((string) $state, '100', 2))
                ->dehydrateStateUsing(fn (?string $state) => $state === null || $state === '' ? null : Units::rupeesToPaise($state))
                ->visible(fn (Get $get) => $get('category') === 'by_product' && (auth()->user()?->can('ledger.post') ?? false)),
            Select::make('status')->options(['active' => __('Active'), 'inactive' => __('Inactive')])->default('active')->required(),
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
                TextColumn::make('pct_code')->label(__('PCT'))->placeholder('—'),
                TextColumn::make('taxRate.name')->label(__('Sales tax'))->placeholder('—'),
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

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales;

use App\Filament\Resources\Procurement\SaudaResource;
use App\Filament\Resources\Sales\SalesSaudaResource\Pages;
use App\Filament\Support\Units;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Illuminate\Database\Eloquent\Builder;

/** Sales deals: same sauda model, lifecycle and maker-checker as purchases, buyer side. */
class SalesSaudaResource extends SaudaResource
{
    public const DIRECTION = 'sale';

    protected static ?string $navigationGroup = 'Sales & dispatch';

    protected static ?string $navigationLabel = 'Sales saudas';

    protected static ?string $modelLabel = 'sales sauda';

    protected static ?string $slug = 'sales/saudas';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Buyer & product')->columns(3)->schema([
                Select::make('party_id')->label('Buyer')->required()->searchable()->preload()
                    ->relationship('party', 'name', fn (Builder $query) => $query->where('is_customer', true)),
                Select::make('broker_party_id')->label('Broker / agent')->searchable()->preload()
                    ->relationship('broker', 'name', fn (Builder $query) => $query->where('is_broker', true)),
                Select::make('product_id')->label('Product')->required()->preload()
                    ->relationship('product', 'name', fn (Builder $query) => $query->whereIn('category', ['rice', 'by_product'])),
            ]),
            Section::make('Terms')->columns(3)->schema([
                TextInput::make('quantity_quintal')->label('Quantity (quintal)')->required()->regex(Units::DECIMAL_3),
                TextInput::make('rate_rupees')->label('Rate (₹ per quintal, before GST)')->required()->regex(Units::DECIMAL_2),
                TextInput::make('tolerance_percent')->label('Delivery tolerance (± %)')->default('5')->regex(Units::DECIMAL_2),
                TextInput::make('brokerage_rupees')->label('Brokerage (₹ per quintal)')->default('0')->regex(Units::DECIMAL_2),
                TextInput::make('payment_days')->label('Credit days')->integer()->minValue(0)->maxValue(365)->default(0),
                DatePicker::make('delivery_from')->required(),
                DatePicker::make('delivery_to')->required()->afterOrEqual('delivery_from'),
            ]),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function toTerms(array $data): array
    {
        $terms = parent::toTerms($data + ['moisture_max_percent' => null]);
        unset($terms['quality_terms']);

        return ['direction' => 'sale'] + $terms;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesSaudas::route('/'),
            'create' => Pages\CreateSalesSauda::route('/create'),
            'view' => Pages\ViewSalesSauda::route('/{record}'),
        ];
    }
}

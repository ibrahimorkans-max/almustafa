<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales;

use App\Domain\Pricing\PricingService;
use App\Filament\Resources\Procurement\SaudaResource;
use App\Filament\Resources\Sales\SalesSaudaResource\Pages;
use App\Filament\Support\ProductSelect;
use App\Filament\Support\Units;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Sales deals: same sauda model, lifecycle and maker-checker as purchases, buyer side. */
class SalesSaudaResource extends SaudaResource
{
    public const DIRECTION = 'sale';

    protected static ?string $navigationGroup = 'Sales & dispatch';

    protected static ?string $navigationLabel = 'Sales saudas';

    protected static ?string $modelLabel = 'sales sauda';

    protected static ?string $slug = 'sales/saudas';

    protected static ?int $navigationSort = 10;

    /** The sales desk works its own deals: seeing needs 'sales.view' (or a deal right), making them 'sales.order'. */
    public static function canViewAny(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount
            && ($me->hasPermission('sales.view') || $me->hasPermission('deal.view') || $me->hasPermission('deal.view_own'));
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->hasPermission('sales.order');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Buyer & product')->columns(3)->schema([
                Select::make('party_id')->label('Buyer')->required()->searchable()->preload()
                    ->relationship('party', 'name', fn (Builder $query) => $query->where('is_customer', true)),
                Select::make('broker_party_id')->label('Broker / agent')->searchable()->preload()
                    ->relationship('broker', 'name', fn (Builder $query) => $query->where('is_broker', true)),
                ProductSelect::make('product_id', ['rice', 'by_product'])->required(),
            ]),
            Section::make('Terms')->columns(3)->schema([
                TextInput::make('quantity_maund')->label('Quantity (maund)')->required()->regex(Units::DECIMAL_3),
                TextInput::make('rate_rupees')->label('Rate (Rs per maund, before sales tax)')->required()->regex(Units::DECIMAL_2),
                TextInput::make('tolerance_percent')->label('Delivery tolerance (± %)')->default('5')->regex(Units::DECIMAL_2),
                TextInput::make('brokerage_rupees')->label('Brokerage (Rs per maund)')->default('0')->regex(Units::DECIMAL_2),
                TextInput::make('payment_days')->label('Credit days')->integer()->minValue(0)->maxValue(365)->default(0),
                DatePicker::make('delivery_from')->required(),
                DatePicker::make('delivery_to')->required()->afterOrEqual('delivery_from'),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $base = parent::infolist($infolist)->getComponents();

        return $infolist->schema([
            ...$base,
            InfoSection::make('Price list')->columns(2)->schema([
                TextEntry::make('list_rate')->label('List rate / maund today')
                    ->state(fn (Sauda $r) => Units::paiseToRupees(app(PricingService::class)->listRate($r->product)) ?: '—'),
                TextEntry::make('vs_list')->label('Deal vs list')
                    ->state(function (Sauda $r): string {
                        $list = app(PricingService::class)->listRate($r->product);

                        return $list === null ? 'no list rate' : ($r->rate_paise_per_maund < $list
                            ? 'Below list by '.Units::paiseToRupees($list - $r->rate_paise_per_maund)
                            : 'At or above list');
                    })
                    ->color(fn (string $state) => str_starts_with($state, 'Below') ? 'danger' : null),
            ]),
            SalesBillResource::marginSection(fn (Sauda $r) => [$r->product, (int) $r->rate_paise_per_maund]),
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

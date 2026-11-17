<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales;

use App\Domain\Pricing\PricingService;
use App\Domain\Sales\SalesBillService;
use App\Filament\Resources\Sales\SalesBillResource\Pages;
use App\Filament\Support\Units;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Models\Sales\SalesBill;
use Filament\Facades\Filament;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Sales tax invoices drafted from outbound slips. Amounts are computed, never typed. */
class SalesBillResource extends Resource
{
    use \App\Filament\Concerns\TranslatesResourceLabels;

    protected static ?string $model = SalesBill::class;

    protected static ?string $recordTitleAttribute = 'number';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Sales & dispatch';

    protected static ?string $navigationLabel = 'Tax invoices';

    protected static ?string $modelLabel = 'tax invoice';

    protected static ?string $slug = 'sales/invoices';

    protected static ?int $navigationSort = 30;

    public static function getNavigationBadge(): ?string
    {
        $drafts = SalesBill::query()->where('status', SalesBill::DRAFT)->count();

        return $drafts > 0 ? (string) $drafts : null;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'posted' => 'success', 'cancelled' => 'danger', default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        $rs = fn (int $state) => Units::paiseToRupees($state);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'gateEntry']))
            ->columns([
                TextColumn::make('bill_date')->label(__('Date'))->date('d M Y')->sortable(),
                TextColumn::make('number')->label(__('Invoice'))->placeholder('draft')->searchable(),
                TextColumn::make('party.name')->label(__('Buyer'))->searchable(),
                TextColumn::make('gateEntry.registration_no')->label(__('Vehicle')),
                TextColumn::make('quantity_kg')->label(__('Qty (maund)'))->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                TextColumn::make('taxable_paise')->label(__('Value excl. tax'))->alignEnd()->formatStateUsing($rs),
                TextColumn::make('tax')->label(__('Sales tax'))->alignEnd()
                    ->state(fn (SalesBill $r) => $r->isSalesTaxInvoice() ? Units::paiseToRupees($r->taxTotal()) : '—'),
                TextColumn::make('total_paise')->label(__('Total'))->alignEnd()->formatStateUsing($rs),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
            ])
            ->defaultSort('bill_date', 'desc')
            ->filters([SelectFilter::make('status')->options(['draft' => __('Draft (to post)'), 'posted' => __('Posted'), 'cancelled' => __('Cancelled')])])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('number')->label(__('Invoice no.'))->placeholder(__('assigned when posted')),
                TextEntry::make('party.name')->label(__('Buyer')),
                TextEntry::make('buyer_strn')->label(__('Buyer STRN'))->placeholder(__('Unregistered')),
                TextEntry::make('bill_date')->date('d M Y'),
                TextEntry::make('due_date')->date('d M Y'),
                TextEntry::make('invoice_type')->label(__('Type'))
                    ->formatStateUsing(fn (string $state) => $state === 'sales_tax' ? 'Sales tax invoice' : 'Invoice (mill not sales-tax registered)'),
                TextEntry::make('gateEntry.number')->label(__('Weighment slip')),
            ]),
            Section::make(__('Amounts'))->columns(4)->schema([
                TextEntry::make('quantity_kg')->label(__('Net weight (kg)'))->fontFamily('mono'),
                TextEntry::make('rate_paise_per_maund')->label(__('Rate / maund'))->formatStateUsing($rs),
                TextEntry::make('pct_code')->label(__('PCT code'))->placeholder('—'),
                TextEntry::make('tax_rate_bp')->label(__('Sales tax rate'))
                    ->formatStateUsing(fn (int $state, SalesBill $record) => $record->tax_exempt ? 'Exempt' : Units::bpToPercent($state)),
                TextEntry::make('taxable_paise')->label(__('Value excluding tax'))->formatStateUsing($rs),
                TextEntry::make('sales_tax_paise')->label(__('Sales tax'))->formatStateUsing($rs),
                TextEntry::make('further_tax_paise')->label(__('Further tax (unregistered buyer)'))->formatStateUsing($rs),
                TextEntry::make('round_off_paise')->label(__('Round off'))->formatStateUsing(fn (int $state) => ($state < 0 ? '− ' : '').Units::paiseToRupees(abs($state))),
                TextEntry::make('total_paise')->label(__('Invoice total'))->formatStateUsing($rs)->weight('bold'),
                TextEntry::make('outstanding')->label(__('Outstanding'))
                    ->state(fn (SalesBill $record) => Units::paiseToRupees(app(SalesBillService::class)->outstanding($record))),
                TextEntry::make('note')->placeholder('—'),
            ]),
            self::marginSection(fn (SalesBill $r) => [$r->product, $r->rate_paise_per_maund]),
        ]);
    }

    /**
     * Confidential cost and margin. Rendered only for holders of `pricing.margin.view`; nobody else
     * receives these figures at all (hidden, never altered).
     *
     * @param  callable(SalesBill|Sauda): array{0: Product, 1: int}  $source
     */
    public static function marginSection(callable $source): Section
    {
        $margin = function ($record) use ($source): ?array {
            [$product, $rate] = $source($record);

            return app(PricingService::class)->margin(Filament::auth()->user(), $product, $rate);
        };

        return Section::make(__('Cost and margin (confidential)'))->columns(3)
            ->visible(fn () => Filament::auth()->user()?->can('pricing.margin.view') ?? false)
            ->schema([
                TextEntry::make('margin_cost')->label(__('Cost / maund (weighted average)'))
                    ->state(fn ($record) => Units::paiseToRupees($margin($record)['cost_paise_per_maund'] ?? null)),
                TextEntry::make('margin_value')->label(__('Margin / maund'))
                    ->state(fn ($record) => Units::paiseToRupees($margin($record)['margin_paise_per_maund'] ?? null)),
                TextEntry::make('margin_pct')->label(__('Margin %'))
                    ->state(fn ($record) => Units::bpToPercent($margin($record)['margin_bp'] ?? null)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesBills::route('/'),
            'view' => Pages\ViewSalesBill::route('/{record}'),
        ];
    }
}

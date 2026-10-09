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
    protected static ?string $model = SalesBill::class;

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
                TextColumn::make('bill_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('number')->label('Invoice')->placeholder('draft')->searchable(),
                TextColumn::make('party.name')->label('Buyer')->searchable(),
                TextColumn::make('gateEntry.registration_no')->label('Vehicle'),
                TextColumn::make('quantity_kg')->label('Qty (maund)')->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                TextColumn::make('taxable_paise')->label('Value excl. tax')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('tax')->label('Sales tax')->alignEnd()
                    ->state(fn (SalesBill $r) => $r->isSalesTaxInvoice() ? Units::paiseToRupees($r->taxTotal()) : '—'),
                TextColumn::make('total_paise')->label('Total')->alignEnd()->formatStateUsing($rs),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
            ])
            ->defaultSort('bill_date', 'desc')
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft (to post)', 'posted' => 'Posted', 'cancelled' => 'Cancelled'])])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $rs = fn (?int $state) => Units::paiseToRupees($state);

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('number')->label('Invoice no.')->placeholder('assigned when posted'),
                TextEntry::make('party.name')->label('Buyer'),
                TextEntry::make('buyer_strn')->label('Buyer STRN')->placeholder('Unregistered'),
                TextEntry::make('bill_date')->date('d M Y'),
                TextEntry::make('due_date')->date('d M Y'),
                TextEntry::make('invoice_type')->label('Type')
                    ->formatStateUsing(fn (string $state) => $state === 'sales_tax' ? 'Sales tax invoice' : 'Invoice (mill not sales-tax registered)'),
                TextEntry::make('gateEntry.number')->label('Weighment slip'),
            ]),
            Section::make('Amounts')->columns(4)->schema([
                TextEntry::make('quantity_kg')->label('Net weight (kg)')->fontFamily('mono'),
                TextEntry::make('rate_paise_per_maund')->label('Rate / maund')->formatStateUsing($rs),
                TextEntry::make('pct_code')->label('PCT code')->placeholder('—'),
                TextEntry::make('tax_rate_bp')->label('Sales tax rate')
                    ->formatStateUsing(fn (int $state, SalesBill $record) => $record->tax_exempt ? 'Exempt' : Units::bpToPercent($state)),
                TextEntry::make('taxable_paise')->label('Value excluding tax')->formatStateUsing($rs),
                TextEntry::make('sales_tax_paise')->label('Sales tax')->formatStateUsing($rs),
                TextEntry::make('further_tax_paise')->label('Further tax (unregistered buyer)')->formatStateUsing($rs),
                TextEntry::make('round_off_paise')->label('Round off')->formatStateUsing(fn (int $state) => ($state < 0 ? '− ' : '').Units::paiseToRupees(abs($state))),
                TextEntry::make('total_paise')->label('Invoice total')->formatStateUsing($rs)->weight('bold'),
                TextEntry::make('outstanding')->label('Outstanding')
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

        return Section::make('Cost and margin (confidential)')->columns(3)
            ->visible(fn () => Filament::auth()->user()?->can('pricing.margin.view') ?? false)
            ->schema([
                TextEntry::make('margin_cost')->label('Cost / maund (weighted average)')
                    ->state(fn ($record) => Units::paiseToRupees($margin($record)['cost_paise_per_maund'] ?? null)),
                TextEntry::make('margin_value')->label('Margin / maund')
                    ->state(fn ($record) => Units::paiseToRupees($margin($record)['margin_paise_per_maund'] ?? null)),
                TextEntry::make('margin_pct')->label('Margin %')
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

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Domain\Accounts\PurchaseBillService;
use App\Filament\Resources\Accounts\PurchaseBillResource\Pages;
use App\Filament\Support\Units;
use App\Models\Accounts\PurchaseBill;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Purchase bills drafted from weighment slips. Amounts are computed, never typed. */
class PurchaseBillResource extends Resource
{
    protected static ?string $model = PurchaseBill::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Purchase bills';

    protected static ?string $slug = 'purchase-bills';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        $drafts = PurchaseBill::query()->where('status', PurchaseBill::DRAFT)->count();

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
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'gateEntry', 'sauda']))
            ->columns([
                TextColumn::make('bill_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('number')->label('Voucher')->placeholder('draft')->searchable(),
                TextColumn::make('party.name')->label('Party')->searchable(),
                TextColumn::make('gateEntry.number')->label('Slip'),
                TextColumn::make('sauda.number')->label('Sauda'),
                TextColumn::make('payable_kg')->label('Payable (maund)')->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                TextColumn::make('net_paise')->label('Amount')->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('due_date')->label('Due')->date('d M Y')->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
            ])
            ->defaultSort('bill_date', 'desc')
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft (to review)', 'posted' => 'Posted', 'cancelled' => 'Cancelled'])])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $rupees = fn (?int $state) => Units::paiseToRupees($state);

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('number')->label('Voucher')->placeholder('not posted'),
                TextEntry::make('party.name')->label('Party'),
                TextEntry::make('broker.name')->label('Broker')->placeholder('—'),
                TextEntry::make('gateEntry.number')->label('Weighment slip'),
                TextEntry::make('sauda.number')->label('Sauda'),
                TextEntry::make('bill_date')->date('d M Y'),
                TextEntry::make('due_date')->date('d M Y'),
            ]),
            Section::make('Computation (from the frozen slip and sauda terms)')->columns(4)->schema([
                TextEntry::make('payable_kg')->label('Payable weight (kg)')->fontFamily('mono'),
                TextEntry::make('rate_paise_per_maund')->label('Rate / maund')->formatStateUsing($rupees),
                TextEntry::make('gross_paise')->label('Gross')->formatStateUsing($rupees),
                TextEntry::make('rate_cut_paise')->label('Quality rate cut')->formatStateUsing($rupees),
                TextEntry::make('round_off_paise')->label('Round off')->formatStateUsing(fn (int $state) => ($state < 0 ? '− ' : '').Units::paiseToRupees(abs($state))),
                TextEntry::make('net_paise')->label('Owed to party')->formatStateUsing($rupees)->weight('bold'),
                TextEntry::make('brokerage_paise')->label('Brokerage (to broker)')->formatStateUsing($rupees),
                TextEntry::make('wht_paise')->label('Income tax withheld u/s 153')
                    ->formatStateUsing(fn (int $state, PurchaseBill $record) => $state > 0
                        ? Units::paiseToRupees($state).' ('.Units::bpToPercent($record->wht_rate_bp).' of '.Units::paiseToRupees($record->wht_base_paise).', '.($record->wht_filer ? 'filer' : 'non-filer').')'
                        : ($record->status === PurchaseBill::DRAFT ? 'worked out on posting' : '—')),
                TextEntry::make('outstanding')->label('Outstanding')
                    ->state(fn (PurchaseBill $record) => Units::paiseToRupees(app(PurchaseBillService::class)->outstanding($record))),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseBills::route('/'),
            'view' => Pages\ViewPurchaseBill::route('/{record}'),
        ];
    }
}

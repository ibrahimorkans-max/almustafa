<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Filament\Resources\Accounts\VoucherResource\Pages;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherLine;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** All posted vouchers (append-only). */
class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Vouchers';

    protected static ?string $slug = 'vouchers';

    protected static ?int $navigationSort = 40;

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        'sales' => 'Sales', 'purchase' => 'Purchase', 'payment' => 'Payment', 'receipt' => 'Receipt', 'journal' => 'Journal',
        'contra' => 'Contra', 'debit_note' => 'Debit note', 'credit_note' => 'Credit note', 'opening' => 'Opening',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('voucher_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('number')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => self::TYPE_LABELS[$state] ?? $state),
                TextColumn::make('narration')->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('total_paise')->label('Amount')->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('reverses_id')->label('')->badge()->color('danger')
                    ->formatStateUsing(fn () => 'Reversal')->placeholder(''),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options(self::TYPE_LABELS),
                Filter::make('dates')->form([DatePicker::make('from'), DatePicker::make('to')])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('voucher_date', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->where('voucher_date', '<=', $d))),
            ])
            ->actions([ViewAction::make(), PrintButton::row('voucher')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('type')->formatStateUsing(fn (string $state) => self::TYPE_LABELS[$state] ?? $state)->badge(),
                TextEntry::make('voucher_date')->date('d M Y'),
                TextEntry::make('total_paise')->label('Amount')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('narration')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Entries')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('account.name')->label('Ledger')
                        ->state(fn (VoucherLine $record) => $record->account->displayName()),
                    TextEntry::make('dr_paise')->label('Debit')->formatStateUsing(fn (int $state) => $state > 0 ? Units::paiseToRupees($state) : ''),
                    TextEntry::make('cr_paise')->label('Credit')->formatStateUsing(fn (int $state) => $state > 0 ? Units::paiseToRupees($state) : ''),
                    TextEntry::make('memo')->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVouchers::route('/'),
            'view' => Pages\ViewVoucher::route('/{record}'),
        ];
    }
}

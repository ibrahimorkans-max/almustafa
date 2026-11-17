<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory;

use App\Filament\Resources\Inventory\StockEntryResource\Pages;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** The stock ledger: every movement ever, append-only. Corrections show as reversal rows. */
class StockEntryResource extends Resource
{
    use \App\Filament\Concerns\TranslatesResourceLabels;

    protected static ?string $model = StockEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Stock ledger';

    protected static ?string $modelLabel = 'stock movement';

    protected static ?string $slug = 'stock-ledger';

    protected static ?int $navigationSort = 20;

    /** @var array<string, string> */
    public const MOVEMENTS = [
        'receipt' => 'Receipt (gate)', 'opening' => 'Opening stock (from paper)', 'production_issue' => 'Issued to production', 'production_output' => 'Production output',
        'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in', 'adjustment' => 'Count adjustment',
        'reversal' => 'Reversal', 'dispatch' => 'Dispatch',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product', 'godown']))
            ->columns([
                TextColumn::make('occurred_at')->label(__('When'))->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('product.name')->label(__('Product')),
                TextColumn::make('godown.name')->label(__('Godown')),
                TextColumn::make('movement')->badge()->formatStateUsing(fn (string $state) => self::MOVEMENTS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'reversal', 'adjustment' => 'warning', 'receipt', 'production_output', 'transfer_in' => 'success', default => 'gray',
                    }),
                TextColumn::make('quantity_kg')->label('kg')->alignEnd()->fontFamily('mono')
                    ->color(fn (string $state) => str_starts_with($state, '-') ? 'danger' : 'success'),
                TextColumn::make('note')->label(__('Reference'))->limit(40)->placeholder('—'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('product_id')->label(__('Product'))->searchable()->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('godown_id')->label(__('Godown'))->options(fn () => Godown::query()->orderBy('code')->pluck('name', 'id')),
                SelectFilter::make('movement')->options(\App\Support\Locales::options(self::MOVEMENTS)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListStockEntries::route('/')];
    }
}

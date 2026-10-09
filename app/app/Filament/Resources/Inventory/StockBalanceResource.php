<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory;

use App\Filament\Resources\Inventory\StockBalanceResource\Pages;
use App\Filament\Support\Units;
use App\Models\Inventory\StockBalance;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Current stock per product and godown — a projection of the stock ledger (read-only). */
class StockBalanceResource extends Resource
{
    protected static ?string $model = StockBalance::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Stock in hand';

    protected static ?string $modelLabel = 'stock balance';

    protected static ?string $slug = 'stock';

    protected static ?int $navigationSort = 10;

    /** @var array<string, string> */
    public const CATEGORY_LABELS = ['paddy' => 'Paddy', 'rice' => 'Rice', 'by_product' => 'By-products', 'packing' => 'Packing', 'consumable' => 'Consumables', 'other' => 'Other'];

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'godown'])
                ->join('products', 'products.id', '=', 'stock_balances.product_id')
                ->select('stock_balances.*', 'products.category as product_category'))
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('godown.name')->label('Godown'),
                TextColumn::make('quantity_kg')->label('Quantity (kg)')->alignEnd()->fontFamily('mono')->sortable()
                    ->color(fn (string $state) => bccomp($state, '0', 3) === 0 ? 'gray' : null),
                TextColumn::make('maund')->label('Maund')->alignEnd()
                    ->state(fn (StockBalance $record) => Units::kgToMaund($record->quantity_kg)),
                TextColumn::make('updated_at')->label('Last movement')->dateTime('d M Y H:i')->sortable(),
            ])
            ->groups([
                Group::make('product_category')->label('Category')
                    ->getTitleFromRecordUsing(fn (StockBalance $record) => self::CATEGORY_LABELS[$record->getAttribute('product_category')] ?? 'Other'),
            ])
            ->defaultGroup('product_category')
            ->defaultSort('product_category')
            ->filters([
                SelectFilter::make('category')->options(self::CATEGORY_LABELS)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('products.category', $data['value']) : $query),
                SelectFilter::make('godown_id')->label('Godown')->options(fn () => Godown::query()->orderBy('code')->pluck('name', 'id')),
                SelectFilter::make('product_id')->label('Product')->searchable()->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id')),
                TernaryFilter::make('non_zero')->label('Only with stock')->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where('stock_balances.quantity_kg', '<>', 0),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                Action::make('ledger')->label('Movements')->icon('heroicon-o-list-bullet')->color('gray')
                    ->url(fn (StockBalance $record) => StockEntryResource::getUrl('index', ['tableFilters' => [
                        'product_id' => ['value' => $record->product_id], 'godown_id' => ['value' => $record->godown_id],
                    ]])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListStockBalances::route('/')];
    }
}

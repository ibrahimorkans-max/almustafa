<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory;

use App\Filament\Resources\ApprovalRequestResource;
use App\Filament\Resources\Inventory\StockCountResource\Pages;
use App\Models\Inventory\StockCount;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Physical counts and their adjustments (each difference goes through maker-checker). */
class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Stock counts';

    protected static ?string $slug = 'stock-counts';

    protected static ?int $navigationSort = 50;

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'applied' => 'success', 'rejected' => 'danger', default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product', 'godown']))
            ->columns([
                TextColumn::make('count_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('product.name')->label('Product'),
                TextColumn::make('godown.name')->label('Godown'),
                TextColumn::make('system_kg')->label('Books (kg)')->alignEnd()->fontFamily('mono'),
                TextColumn::make('counted_kg')->label('Counted (kg)')->alignEnd()->fontFamily('mono'),
                TextColumn::make('difference_kg')->label('Difference (kg)')->alignEnd()->fontFamily('mono')
                    ->color(fn (string $state) => bccomp($state, '0', 3) < 0 ? 'danger' : (bccomp($state, '0', 3) > 0 ? 'warning' : 'success')),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => $state === 'pending' ? 'Waiting for approval' : ucfirst($state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(['pending' => 'Waiting for approval', 'applied' => 'Applied', 'rejected' => 'Rejected'])])
            ->actions([
                Action::make('approval')->label('Approval')->icon('heroicon-o-shield-check')->color('gray')
                    ->visible(fn (StockCount $record) => $record->approval_request_id !== null)
                    ->url(fn (StockCount $record) => ApprovalRequestResource::getUrl('view', ['record' => $record->approval_request_id])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListStockCounts::route('/')];
    }
}

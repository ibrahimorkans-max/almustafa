<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory;

use App\Filament\Resources\Inventory\RequisitionResource\Pages;
use App\Filament\Support\ArchivedFilter;
use App\Filament\Support\Units;
use App\Models\Inventory\Requisition;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\ProductionBatch;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RequisitionResource extends Resource
{
    protected static ?string $model = Requisition::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static ?string $navigationGroup = 'Stock & production';

    protected static ?string $navigationLabel = 'Requisitions';

    protected static ?string $slug = 'requisitions';

    protected static ?int $navigationSort = 30;

    /** @return array<string, string> */
    public static function godownOptions(): array
    {
        return Godown::query()->where('status', 'active')->orderBy('code')->get()
            ->mapWithKeys(fn (Godown $g) => [$g->id => "{$g->code} — {$g->name}"])->all();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Radio::make('destination')->options(['batch' => 'To a production batch (milling)', 'godown' => 'To another godown (transfer)'])
                ->default('batch')->required()->live()->columnSpanFull(),
            Select::make('to_batch_id')->label('Batch')->required(fn (Get $get) => $get('destination') === 'batch')
                ->visible(fn (Get $get) => $get('destination') === 'batch')->live()
                ->options(fn () => ProductionBatch::query()->where('status', 'open')->with('paddy')->get()
                    ->mapWithKeys(fn (ProductionBatch $b) => [$b->id => "{$b->number} — {$b->paddy->name}"])),
            Select::make('product_id')->label('Product')->required()->searchable()
                ->options(fn (Get $get) => Product::query()->where('is_stock_item', true)
                    ->when($get('destination') === 'batch' && $get('to_batch_id'), fn ($q) => $q->whereKey(ProductionBatch::query()->whereKey($get('to_batch_id'))->value('paddy_product_id')))
                    ->orderBy('name')->pluck('name', 'id')),
            Select::make('from_godown_id')->label('From godown')->required()->options(fn () => self::godownOptions()),
            Select::make('to_godown_id')->label('To godown')->required(fn (Get $get) => $get('destination') === 'godown')
                ->visible(fn (Get $get) => $get('destination') === 'godown')->options(fn () => self::godownOptions()),
            TextInput::make('quantity_kg')->label('Quantity (kg)')->required()->regex(Units::DECIMAL_3),
            Textarea::make('purpose')->maxLength(1000)->columnSpanFull(),
        ]);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'issued' => 'success', 'approved' => 'info', 'rejected', 'cancelled' => 'danger', default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product', 'fromGodown', 'toGodown', 'toBatch']))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('product.name')->label('Product'),
                TextColumn::make('quantity_kg')->label('kg')->alignEnd()->fontFamily('mono'),
                TextColumn::make('fromGodown.name')->label('From'),
                TextColumn::make('destination')->label('To')
                    ->state(fn (Requisition $r) => $r->toBatch->number ?? $r->toGodown?->name),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('created_at')->label('Requested')->dateTime('d M H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                ArchivedFilter::make(),
                SelectFilter::make('status')->options(array_combine(
                    ['requested', 'approved', 'issued', 'rejected', 'cancelled'],
                    ['Waiting for approval', 'Approved (to issue)', 'Issued', 'Rejected', 'Cancelled'],
                )),
            ])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('quantity_kg')->label('Quantity (kg)')->fontFamily('mono'),
                TextEntry::make('fromGodown.name')->label('From godown'),
                TextEntry::make('toBatch.number')->label('To batch')->placeholder('—'),
                TextEntry::make('toGodown.name')->label('To godown')->placeholder('—'),
                TextEntry::make('purpose')->placeholder('—'),
                TextEntry::make('approved_at')->label('Decided')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('decision_note')->label('Decision note')->placeholder('—'),
                TextEntry::make('issued_at')->label('Issued')->dateTime('d M Y H:i')->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRequisitions::route('/'),
            'create' => Pages\CreateRequisition::route('/create'),
            'view' => Pages\ViewRequisition::route('/{record}'),
        ];
    }
}

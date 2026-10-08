<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales;

use App\Filament\Resources\Inventory\RequisitionResource;
use App\Filament\Resources\Sales\DispatchOrderResource\Pages;
use App\Filament\Support\Units;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DispatchOrderResource extends Resource
{
    protected static ?string $model = DispatchOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Sales & dispatch';

    protected static ?string $navigationLabel = 'Dispatch orders';

    protected static ?string $slug = 'dispatch-orders';

    protected static ?int $navigationSort = 20;

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'pending_credit' => 'Waiting: credit override', 'open' => 'Open', 'loading' => 'Vehicle at gate',
        'dispatched' => 'Dispatched', 'cancelled' => 'Cancelled',
    ];

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'dispatched' => 'success', 'cancelled' => 'danger', 'pending_credit' => 'warning', default => 'info',
        };
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('sauda_id')->label('Sales sauda')->required()->searchable()
                ->options(fn () => Sauda::query()->where('direction', 'sale')->whereIn('status', [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY])
                    ->with(['party', 'product'])->get()
                    ->mapWithKeys(fn (Sauda $s) => [$s->id => "{$s->number} — {$s->party->name} · {$s->product->name} · {$s->remaining()->in('quintal', 2)} qtl left"])),
            Select::make('godown_id')->label('Load from godown')->required()->options(fn () => RequisitionResource::godownOptions()),
            TextInput::make('quantity_kg')->label('Quantity (kg)')->required()->regex(Units::DECIMAL_3),
            TextInput::make('bag_count')->label('Bags (packed rice)')->integer()->minValue(1),
            TextInput::make('bag_weight_kg')->label('Bag size (kg)')->regex(Units::DECIMAL_3)->requiredWith('bag_count'),
            Textarea::make('note')->maxLength(1000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'product', 'sauda', 'gateEntry']))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('party.name')->label('Buyer')->searchable(),
                TextColumn::make('product.name')->label('Product'),
                TextColumn::make('quantity_kg')->label('Quantity (qtl)')->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToQuintal($state)),
                TextColumn::make('estimated_value_paise')->label('Value incl. GST')->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                TextColumn::make('gateEntry.registration_no')->label('Vehicle')->placeholder('—'),
                TextColumn::make('created_at')->label('Created')->dateTime('d M H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(self::STATUS_LABELS)])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                TextEntry::make('sauda.number')->label('Sauda'),
                TextEntry::make('party.name')->label('Buyer'),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('godown.name')->label('Load from'),
                TextEntry::make('quantity_kg')->label('Quantity (kg)')->fontFamily('mono'),
                TextEntry::make('bags')->label('Bags')->state(fn (DispatchOrder $r) => $r->bag_count ? "{$r->bag_count} × {$r->bag_weight_kg} kg" : '—'),
                TextEntry::make('estimated_value_paise')->label('Value incl. GST')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('gateEntry.number')->label('Gate entry')->placeholder('—'),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDispatchOrders::route('/'),
            'create' => Pages\CreateDispatchOrder::route('/create'),
            'view' => Pages\ViewDispatchOrder::route('/{record}'),
        ];
    }
}

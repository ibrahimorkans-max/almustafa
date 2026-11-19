<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales;

use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Inventory\RequisitionResource;
use App\Filament\Resources\Sales\DispatchOrderResource\Pages;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\QuickBill;
use App\Support\Locales;
use App\Support\Values\Money;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DispatchOrderResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = DispatchOrder::class;

    protected static ?string $recordTitleAttribute = 'number';

    /** Found by the search box in the top bar (partial match, this mill only, and only for people who may open the list). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

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
        $gate = fn (Get $get) => $get('mode') !== 'direct';

        return $form->columns(2)->schema([
            ToggleButtons::make('mode')->label(__('How does it leave?'))->inline()->required()->default('gate')->live()->columnSpanFull()
                ->options(['gate' => __('Vehicle at the weighbridge'), 'direct' => __('Direct — by hand, bike, small quantity (weight typed)')])
                ->icons(['gate' => 'heroicon-o-truck', 'direct' => 'heroicon-o-hand-raised']),
            Hidden::make('kind')->default('sale'),
            Select::make('sauda_id')->label(__('Sales sauda'))->required()->searchable()->live()
                ->options(fn () => Sauda::query()->where('direction', 'sale')->whereIn('status', [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY])
                    ->with(['party', 'product'])->get()
                    ->mapWithKeys(fn (Sauda $s) => [$s->id => "{$s->number} — {$s->party->name} · {$s->product->name} · {$s->remaining()->in('maund', 2)} maund left · ".Money::paise((int) $s->rate_paise_per_maund)->format().' / md']))
                ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                    $s = $state ? Sauda::query()->with('product')->find($state) : null;
                    if ($s === null || $get('mode') !== 'direct') {
                        return;
                    }
                    $set('lines', [(string) Str::uuid() => ['product_id' => $s->product_id, 'description' => $s->product->name, 'variety' => $s->product->variety,
                        'rate' => Money::paise((int) $s->rate_paise_per_maund)->toDecimal(), 'tare_kg' => '0', 'deduction_per_bag_kg' => '0', 'other_deduction_kg' => '0']]);
                }),
            Select::make('godown_id')->label(__('Load from godown'))->required($gate)->visible($gate)->options(fn () => RequisitionResource::godownOptions()),
            TextInput::make('quantity_kg')->label(__('Quantity (kg)'))->required($gate)->visible($gate)->regex(Units::DECIMAL_3),
            TextInput::make('bag_count')->label(__('Bags (packed rice)'))->integer()->minValue(1)->visible($gate),
            TextInput::make('bag_weight_kg')->label(__('Bag size (kg)'))->regex(Units::DECIMAL_3)->requiredWith('bag_count')->visible($gate),
            DateTimePicker::make('billed_at')->label(__('Date and time'))->seconds(false)->default(now('Asia/Karachi'))->timezone('Asia/Karachi')
                ->required(fn (Get $get) => ! $gate($get))->visible(fn (Get $get) => ! $gate($get))->maxDate(now('Asia/Karachi')->addHour()),
            Group::make([
                QuickBillResource::vehicleSection(),
                QuickBillResource::linesSection()->heading(__('Products given (all on one bill)')),
                QuickBillResource::chargesSection(),
                QuickBillResource::paymentSection(),
            ])->columnSpanFull()->visible(fn (Get $get) => ! $gate($get)),
            Textarea::make('note')->maxLength(1000)->columnSpanFull()->visible($gate),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'product', 'sauda', 'gateEntry']))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('party.name')->label(__('Buyer'))->searchable(),
                TextColumn::make('product.name')->label(__('Product')),
                TextColumn::make('quantity_kg')->label(__('Quantity (maund)'))->alignEnd()->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                TextColumn::make('estimated_value_paise')->label(__('Value incl. tax'))->alignEnd()->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                TextColumn::make('gateEntry.registration_no')->label(__('Vehicle'))->placeholder('—')
                    ->state(fn (DispatchOrder $r) => $r->gateEntry->registration_no ?? ($r->quick_bill_id ? __('Direct').' · '.__(QuickBill::VEHICLES[$r->vehicle_type] ?? '—') : null)),
                TextColumn::make('created_at')->label(__('Created'))->dateTime('d M H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(Locales::options(self::STATUS_LABELS))])
            ->actions([ViewAction::make(), PrintButton::row('dispatch')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                TextEntry::make('sauda.number')->label(__('Sauda')),
                TextEntry::make('party.name')->label(__('Buyer')),
                TextEntry::make('product.name')->label(__('Product')),
                TextEntry::make('godown.name')->label(__('Load from')),
                TextEntry::make('quantity_kg')->label(__('Quantity (kg)'))->fontFamily('mono'),
                TextEntry::make('bags')->label(__('Bags'))->state(fn (DispatchOrder $r) => $r->bag_count ? "{$r->bag_count} × {$r->bag_weight_kg} kg" : '—'),
                TextEntry::make('estimated_value_paise')->label(__('Value incl. tax'))->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextEntry::make('gateEntry.number')->label(__('Gate entry'))->placeholder('—'),
                TextEntry::make('quickBill.number')->label(__('Bill (direct dispatch)'))->placeholder('—')
                    ->url(fn (DispatchOrder $r) => $r->quick_bill_id ? QuickBillResource::getUrl('view', ['record' => $r->quick_bill_id]) : null),
                TextEntry::make('dispatched_at')->label(__('Dispatched'))->dateTime('d M Y, h:i A', 'Asia/Karachi')->placeholder('—'),
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

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement;

use App\Filament\Resources\Procurement\SaudaResource\Pages;
use App\Filament\Support\HidesArchived;
use App\Filament\Support\PrintButton;
use App\Filament\Support\Units;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use App\Models\Procurement\SaudaEvent;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SaudaResource extends Resource
{
    use HidesArchived;

    protected static ?string $model = Sauda::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Procurement';

    protected static ?string $navigationLabel = 'Saudas (deals)';

    protected static ?string $modelLabel = 'sauda';

    protected static ?int $navigationSort = 30;

    /** Purchase deals here; sales deals in SalesSaudaResource (same model and lifecycle). */
    public const DIRECTION = 'purchase';

    /** @return Builder<Sauda> */
    public static function getEloquentQuery(): Builder
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();
        /** @var Builder<Sauda> $query */
        $query = parent::getEloquentQuery()->with(['party', 'broker', 'product'])->where('direction', static::DIRECTION);

        return $query->tap(fn (Builder $q) => (new Sauda)->scopeVisibleTo($q, $me));
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            Sauda::STATUS_PENDING => 'warning',
            Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY => 'info',
            Sauda::STATUS_COMPLETED => 'success',
            Sauda::STATUS_CANCELLED, Sauda::STATUS_EXPIRED => 'danger',
            default => 'gray',
        };
    }

    /**
     * Form for creating deals. Quantities in maund (40 kg), rates in Rs per maund.
     * (Filament injects relationship-query closure arguments by NAME: they must be called $query.)
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Parties & product')->columns(3)->schema([
                Select::make('party_id')->label('Seller')->required()->searchable()->preload()
                    ->relationship('party', 'name', fn (Builder $query) => $query->whereRaw('(is_vendor OR is_farmer)')),
                Select::make('broker_party_id')->label('Broker / agent')->searchable()->preload()
                    ->relationship('broker', 'name', fn (Builder $query) => $query->where('is_broker', true)),
                Select::make('product_id')->label('Product')->required()->preload()
                    ->relationship('product', 'name', fn (Builder $query) => $query->where('category', 'paddy')),
            ]),
            Section::make('Terms')->columns(3)->schema([
                TextInput::make('quantity_maund')->label('Quantity (maund)')->required()->regex(Units::DECIMAL_3),
                TextInput::make('rate_rupees')->label('Rate (Rs per maund)')->required()->regex(Units::DECIMAL_2),
                TextInput::make('tolerance_percent')->label('Delivery tolerance (± %)')->default('5')->regex(Units::DECIMAL_2),
                TextInput::make('brokerage_rupees')->label('Brokerage (Rs per maund)')->default('0')->regex(Units::DECIMAL_2),
                TextInput::make('moisture_max_percent')->label('Max moisture (%)')->default('17')->regex(Units::DECIMAL_2),
                TextInput::make('payment_days')->integer()->minValue(0)->maxValue(365)->default(0),
                DatePicker::make('delivery_from')->required(),
                DatePicker::make('delivery_to')->required()->afterOrEqual('delivery_from'),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toTerms(array $data): array
    {
        return [
            'party_id' => $data['party_id'],
            'broker_party_id' => $data['broker_party_id'] ?? null,
            'product_id' => $data['product_id'],
            'quantity_kg' => Units::maundToKg((string) $data['quantity_maund']),
            'rate_paise_per_maund' => Units::rupeesToPaise((string) $data['rate_rupees']),
            'tolerance_bp' => Units::percentToBp((string) ($data['tolerance_percent'] ?? '5')) ?? 500,
            'brokerage_paise_per_maund' => Units::rupeesToPaise((string) ($data['brokerage_rupees'] ?? '0')),
            'quality_terms' => ['moisture_max_bp' => Units::percentToBp((string) ($data['moisture_max_percent'] ?? '17'))],
            'payment_days' => (int) ($data['payment_days'] ?? 0),
            'delivery_from' => $data['delivery_from'],
            'delivery_to' => $data['delivery_to'],
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('party.name')->label('Seller')->searchable(),
                TextColumn::make('broker.name')->label('Broker')->placeholder('—'),
                TextColumn::make('product.name')->label('Product'),
                TextColumn::make('quantity_kg')->label('Qty (maund)')->formatStateUsing(fn (string $state) => Units::kgToMaund($state))->alignEnd(),
                TextColumn::make('rate_paise_per_maund')->label('Rate / maund')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state))->alignEnd(),
                TextColumn::make('delivered_kg')->label('Delivered (maund)')->formatStateUsing(fn (string $state) => Units::kgToMaund($state))->alignEnd(),
                TextColumn::make('status')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextColumn::make('delivery_to')->label('Deliver by')->date()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(array_combine(
                ['draft', 'pending_approval', 'confirmed', 'in_delivery', 'completed', 'cancelled', 'expired'],
                ['Draft', 'Pending approval', 'Confirmed', 'In delivery', 'Completed', 'Cancelled', 'Expired'],
            ))])
            ->actions([ViewAction::make(), PrintButton::row('sauda')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextEntry::make('party.name')->label('Seller'),
                TextEntry::make('broker.name')->label('Broker')->placeholder('—'),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('quantity_kg')->label('Quantity')->formatStateUsing(fn (string $state) => Units::kgToMaund($state).' maund'),
                TextEntry::make('rate_paise_per_maund')->label('Rate')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state).' / maund'),
                TextEntry::make('tolerance_bp')->label('Tolerance')->formatStateUsing(fn (int $state) => '± '.Units::bpToPercent($state)),
                TextEntry::make('brokerage_paise_per_maund')->label('Brokerage')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state).' / maund'),
                TextEntry::make('delivered_kg')->label('Delivered')->formatStateUsing(fn (string $state) => Units::kgToMaund($state).' maund'),
                TextEntry::make('delivery_from')->date(),
                TextEntry::make('delivery_to')->date(),
                TextEntry::make('payment_days'),
                TextEntry::make('quality_terms.moisture_max_bp')->label('Max moisture')->formatStateUsing(fn ($state) => Units::bpToPercent($state === null ? null : (int) $state)),
                TextEntry::make('close_reason')->placeholder('—')->columnSpan(2),
            ]),
            InfoSection::make('History')->schema([
                RepeatableEntry::make('events')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('occurred_at')->label('When')->dateTime(),
                    TextEntry::make('event')->badge(),
                    TextEntry::make('actor.username')->label('Who')->placeholder('system'),
                    TextEntry::make('details')->label('Details')->state(fn (SaudaEvent $record) => collect($record->data ?? [])
                        ->except(['terms'])->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? (is_bool($v) ? ($v ? 'yes' : 'no') : $v) : json_encode($v)))->implode(', ') ?: '—'),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSaudas::route('/'),
            'create' => Pages\CreateSauda::route('/create'),
            'view' => Pages\ViewSauda::route('/{record}'),
        ];
    }
}

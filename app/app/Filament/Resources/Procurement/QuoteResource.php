<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement;

use App\Filament\Resources\Procurement\QuoteResource\Pages;
use App\Filament\Support\ProductSelect;
use App\Filament\Support\Units;
use App\Models\Procurement\Quote;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Procurement';

    protected static ?string $navigationLabel = 'Quotes & negotiation';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Radio::make('by')->label('Offer made by')->options([Quote::BY_SELLER => 'Seller / broker', Quote::BY_MILL => 'Mill'])
                ->default(Quote::BY_SELLER)->inline()->required()->columnSpanFull(),
            Select::make('requirement_id')->label('Against requirement')->preload()
                ->relationship('requirement', 'number', fn (Builder $query) => $query->where('status', 'open')),
            Select::make('party_id')->label('Seller')->required()->searchable()->preload()
                ->relationship('party', 'name', fn (Builder $query) => $query->whereRaw('(is_vendor OR is_farmer)')),
            Select::make('broker_party_id')->label('Broker / agent')->searchable()->preload()
                ->relationship('broker', 'name', fn (Builder $query) => $query->where('is_broker', true)),
            ProductSelect::make('product_id', ['paddy'])->required(),
            TextInput::make('quantity_maund')->label('Quantity (maund)')->required()->regex(Units::DECIMAL_3),
            TextInput::make('rate_rupees')->label('Rate (Rs per maund)')->required()->regex(Units::DECIMAL_2),
            DateTimePicker::make('valid_until'),
            Textarea::make('note')->maxLength(1000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('party.name')->label('Seller')->searchable(),
                TextColumn::make('broker.name')->label('Broker')->placeholder('—'),
                TextColumn::make('quantity_kg')->label('Qty (maund)')->formatStateUsing(fn (string $state) => Units::kgToMaund($state))->alignEnd(),
                TextColumn::make('rate_paise_per_maund')->label('Latest rate / maund')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state))->alignEnd(),
                TextColumn::make('last_offer_by')->label('Last offer by')->badge(),
                TextColumn::make('current_revision')->label('Rev.'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'accepted' => 'success', 'rejected', 'withdrawn', 'expired' => 'danger', default => 'warning',
                }),
                TextColumn::make('updated_at')->since(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([SelectFilter::make('status')->options(['submitted' => 'Submitted', 'countered' => 'Countered', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'expired' => 'Expired'])])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge(),
                TextEntry::make('party.name')->label('Seller'),
                TextEntry::make('broker.name')->label('Broker')->placeholder('—'),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('requirement.number')->label('Requirement')->placeholder('—'),
                TextEntry::make('valid_until')->dateTime()->placeholder('—'),
                TextEntry::make('sauda_id')->label('Sauda')->placeholder('—'),
            ]),
            Section::make('Negotiation')->schema([
                RepeatableEntry::make('revisions')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('revision')->label('#'),
                    TextEntry::make('offered_by')->label('By')->badge(),
                    TextEntry::make('quantity_kg')->label('Qty (maund)')->formatStateUsing(fn (string $state) => Units::kgToMaund($state)),
                    TextEntry::make('rate_paise_per_maund')->label('Rate / maund')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                    TextEntry::make('created_at')->label('When')->dateTime(),
                    TextEntry::make('note')->columnSpanFull()->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'view' => Pages\ViewQuote::route('/{record}'),
        ];
    }
}

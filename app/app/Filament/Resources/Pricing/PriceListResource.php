<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pricing;

use App\Filament\Resources\Pricing\PriceListResource\Pages;
use App\Models\Pricing\PriceList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Selling rate cards. Rates change only through an approved request; every version is kept. */
class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-rupee';

    protected static ?string $navigationGroup = 'Claims & pricing';

    protected static ?string $navigationLabel = 'Price lists';

    protected static ?string $slug = 'price-lists';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            TextInput::make('name')->required()->maxLength(120),
            DatePicker::make('valid_from')->required()->default(now()),
            DatePicker::make('valid_to')->afterOrEqual('valid_from'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('valid_from')->date('d M Y')->sortable(),
                TextColumn::make('valid_to')->date('d M Y')->placeholder('open-ended'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('valid_from', 'desc')
            ->actions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceLists::route('/'),
            'create' => Pages\CreatePriceList::route('/create'),
            'view' => Pages\ViewPriceList::route('/{record}'),
        ];
    }
}

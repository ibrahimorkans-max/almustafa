<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pricing;

use App\Domain\Pricing\PricingService;
use App\Filament\Resources\Pricing\MarkupSettingResource\Pages;
use App\Filament\Support\Units;
use App\Models\Masters\Product;
use App\Models\Pricing\MarkupSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Confidential: default markup over cost per product category, used only to suggest selling rates. */
class MarkupSettingResource extends Resource
{
    protected static ?string $model = MarkupSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Claims & pricing';

    protected static ?string $navigationLabel = 'Markups';

    protected static ?string $slug = 'markups';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('category')->required()->disabledOn('edit')
                ->options(array_combine(Product::CATEGORIES, array_map(fn ($c) => ucfirst(str_replace('_', '-', $c)), Product::CATEGORIES))),
            TextInput::make('markup_bp')->label('Markup over cost (%)')->required()->regex('/^\d{1,4}(\.\d{1,2})?$/')
                ->formatStateUsing(fn ($state) => $state === null ? null : bcdiv((string) $state, '100', 2))
                ->dehydrateStateUsing(fn (string $state) => Units::percentToBp($state)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category')->badge(),
                TextColumn::make('markup_bp')->label('Markup')->formatStateUsing(fn (int $state) => Units::bpToPercent($state)),
                TextColumn::make('updated_at')->label('Changed')->since(),
            ])
            ->actions([
                EditAction::make()->using(fn (MarkupSetting $record, array $data): Model => app(PricingService::class)->setMarkup($record->category, (int) $data['markup_bp'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageMarkupSettings::route('/')];
    }
}

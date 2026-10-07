<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate;

use App\Filament\Resources\Gate\DeductionRuleResource\Pages;
use App\Filament\Support\Units;
use App\Models\Gate\DeductionRule;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quality deduction rules per product. Changes apply to lab decisions taken afterwards;
 * every decision keeps a copy of the rules it used.
 */
class DeductionRuleResource extends Resource
{
    protected static ?string $model = DeductionRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Quality deductions';

    protected static ?string $slug = 'quality-deductions';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('product_id')->label('Product')->required()->preload()
                ->relationship('product', 'name', fn (Builder $query) => $query->where('category', 'paddy')),
            Select::make('parameter')->options(array_combine(DeductionRule::PARAMETERS, array_map(fn ($p) => ucfirst(str_replace('_', ' ', $p)), DeductionRule::PARAMETERS)))
                ->required()->live(),
            Select::make('method')->required()->live()->options([
                'excess_weight' => 'Deduct weight for excess', 'rate_cut' => 'Cut rate per full % excess', 'per_bag' => 'Deduct bag weight',
            ]),
            TextInput::make('free_limit_bp')->label('Free limit (basis points, 1700 = 17 %)')->integer()->minValue(0)->maxValue(10000)->default(0)
                ->hidden(fn (Get $get) => $get('method') === 'per_bag'),
            TextInput::make('factor')->required()->integer()->minValue(0)
                ->label(fn (Get $get) => match ($get('method')) {
                    'rate_cut' => 'Paise per quintal per full 1 %',
                    'per_bag' => 'Grams per bag',
                    default => 'Weight factor (10000 = 1 % weight per 1 % excess)',
                }),
            TextInput::make('reject_above_bp')->label('Reject above (basis points)')->integer()->minValue(0)->maxValue(10000)
                ->hidden(fn (Get $get) => $get('method') === 'per_bag'),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            Placeholder::make('example')->columnSpanFull()->content(fn (Get $get) => match ($get('method')) {
                'excess_weight' => 'Example: free 17 %, factor 10000 → at 18.5 % moisture, 1.5 % of the net weight is deducted.',
                'rate_cut' => 'Example: free 2 %, factor 1000 → at 4.7 %, the rate is cut by ₹20 per quintal (2 full points × ₹10).',
                'per_bag' => 'Example: factor 600 → 400 bags × 0.6 kg = 240 kg deducted for bag weight.',
                default => '',
            }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name'),
                TextColumn::make('parameter')->badge(),
                TextColumn::make('method'),
                TextColumn::make('free_limit_bp')->label('Free')->formatStateUsing(fn (int $state) => Units::bpToPercent($state)),
                TextColumn::make('factor'),
                TextColumn::make('reject_above_bp')->label('Reject above')->formatStateUsing(fn (?int $state) => Units::bpToPercent($state))->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDeductionRules::route('/')];
    }
}

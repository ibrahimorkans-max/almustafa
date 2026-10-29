<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate;

use App\Filament\Resources\Gate\DeductionRuleResource\Pages;
use App\Filament\Support\ProductSelect;
use App\Filament\Support\Units;
use App\Filament\Support\VarietySelect;
use App\Models\Gate\DeductionRule;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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

    protected static ?int $navigationSort = 70;

    public static function form(Form $form): Form
    {
        $unconditional = fn (Get $get) => in_array($get('method'), DeductionRule::UNCONDITIONAL, true);

        return $form->columns(2)->schema([
            TextInput::make('name')->label('Rule name')->required()->maxLength(120)->placeholder('Moisture above 17 %, Bag weight, Dust 1 % …'),
            TextInput::make('code')->label('Rule code')->disabled()->dehydrated(false)->placeholder('Made automatically (DR-…)'),
            ProductSelect::make('product_id', ['paddy'])->required(),
            VarietySelect::make()->helperText('Empty: every variety of this product.'),
            Select::make('method')->label('Deduction type')->required()->live()->options(DeductionRule::METHOD_LABELS),
            Select::make('parameter')->label('Deduction basis (lab test)')
                ->options(array_combine(DeductionRule::PARAMETERS, array_map(fn ($p) => ucfirst(str_replace('_', ' ', $p)), DeductionRule::PARAMETERS)))
                ->required(fn (Get $get) => ! $unconditional($get))->hidden($unconditional)->dehydratedWhenHidden()
                // Rules that need no lab reading are kept under their own basis.
                ->dehydrateStateUsing(fn (?string $state, Get $get) => match ($get('method')) {
                    'per_bag' => 'bags', 'fixed_weight' => 'fixed', 'percent_of_net' => 'net', default => $state,
                }),
            TextInput::make('free_limit_bp')->label('Free limit (basis points, 1700 = 17 %)')->integer()->minValue(0)->maxValue(10000)->default(0)
                ->hidden($unconditional),
            TextInput::make('factor')->required()->integer()->minValue(0)->live(debounce: 500)
                ->label(fn (Get $get) => match ($get('method')) {
                    'rate_cut' => 'Paisa per maund per full 1 %',
                    'per_bag' => 'Grams per bag',
                    'fixed_weight' => 'Deduction weight (grams per lot; 2000 = 2 kg)',
                    'percent_of_net' => 'Percentage of net (basis points; 250 = 2.5 %)',
                    default => 'Weight factor (10000 = 1 % weight per 1 % excess)',
                })
                ->maxValue(fn (Get $get) => $get('method') === 'percent_of_net' ? 10000 : null),
            TextInput::make('reject_above_bp')->label('Reject above (basis points)')->integer()->minValue(0)->maxValue(10000)
                ->hidden($unconditional),
            DatePicker::make('effective_from')->label('Effective from')->default(fn () => now('Asia/Karachi')->toDateString())
                ->helperText('Lots decided before this date are not affected.'),
            TextInput::make('condition_note')->label('Applicable condition')->maxLength(200)->placeholder('e.g. only for lots in jute bags'),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            Placeholder::make('example')->label('Calculated deduction')->columnSpanFull()
                ->content(fn (Get $get) => self::example((string) $get('method'), (int) $get('factor'))),
        ]);
    }

    /** What the rule deducts from a typical 10,000 kg lot of 250 bags, so the figure can be checked before saving. */
    public static function example(string $method, int $factor): string
    {
        $lot = 'On a 10,000 kg lot of 250 bags: ';
        $kg = fn (float $v) => number_format(min($v, 10000), 3).' kg';

        return match ($method) {
            'fixed_weight' => $lot.$kg($factor / 1000).' deducted (kg per lot); accepted '.$kg(max(0, 10000 - $factor / 1000)).'.',
            'percent_of_net' => $lot.$kg($factor).' deducted ('.number_format($factor / 100, 2).' % of net); accepted '.$kg(max(0, 10000 - $factor)).'.',
            'per_bag' => $lot.$kg(250 * $factor / 1000).' deducted (250 bags × '.number_format($factor / 1000, 3).' kg).',
            'excess_weight' => 'Example: free 17 %, factor 10000 → at 18.5 % moisture, 1.5 % of the net weight is deducted.',
            'rate_cut' => 'Example: free 2 %, factor 1000 → at 4.7 %, the rate is cut by Rs 20 per maund (2 full points × Rs 10).',
            default => '',
        }.' A deduction never exceeds the net weight.';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product', 'paddyVariety']))
            ->columns([
                TextColumn::make('code')->fontFamily('mono')->searchable()->placeholder('—'),
                TextColumn::make('name')->searchable()->placeholder('—')->description(fn (DeductionRule $r) => $r->describe()),
                TextColumn::make('product.name'),
                TextColumn::make('paddyVariety.name')->label('Variety')->placeholder('All'),
                TextColumn::make('method')->label('Type')->formatStateUsing(fn (string $state) => DeductionRule::METHOD_LABELS[$state] ?? $state),
                TextColumn::make('free_limit_bp')->label('Free')->formatStateUsing(fn (int $state) => Units::bpToPercent($state))->toggleable(),
                TextColumn::make('factor'),
                TextColumn::make('reject_above_bp')->label('Reject above')->formatStateUsing(fn (?int $state) => Units::bpToPercent($state))->placeholder('—')->toggleable(),
                TextColumn::make('effective_from')->label('From')->date('d M Y')->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive']),
                SelectFilter::make('method')->label('Type')->options(DeductionRule::METHOD_LABELS),
            ])
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDeductionRules::route('/')];
    }
}

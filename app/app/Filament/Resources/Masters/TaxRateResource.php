<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\TaxRateResource\Pages;
use App\Models\Masters\TaxRate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class TaxRateResource extends Resource
{
    use MasterResourceHelpers;

    protected static ?string $model = TaxRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Sales tax rates';

    protected static ?int $navigationSort = 70;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(60)->placeholder('Standard 18%')
                ->unique('tax_rates', 'name', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('entity_id', app(TenantContext::class)->entityId())),
            TextInput::make('sales_tax_rate_bp')->label('Rate (basis points, 1800 = 18%)')->required()->integer()->minValue(0)->maxValue(TaxRate::MAX_RATE_BP)->default(1800),
            Toggle::make('exempt')->label('Exempt (Sixth Schedule)')->helperText('No tax is charged; the supply is reported as exempt rather than zero-rated.'),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('rate')->label('Sales tax')->state(fn (TaxRate $record) => $record->percentLabel()),
                TextColumn::make('status')->badge(),
                static::updatedColumn(),
            ])
            ->defaultSort('sales_tax_rate_bp')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTaxRates::route('/')];
    }
}

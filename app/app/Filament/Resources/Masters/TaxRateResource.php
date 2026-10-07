<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Masters\Concerns\MasterResourceHelpers;
use App\Filament\Resources\Masters\TaxRateResource\Pages;
use App\Models\Masters\TaxRate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

    protected static ?string $navigationLabel = 'Tax rates (GST)';

    protected static ?int $navigationSort = 70;

    public static function form(Form $form): Form
    {
        $slabs = collect(TaxRate::GST_SLABS_BP)->mapWithKeys(fn (int $bp) => [$bp => rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%'])->all();

        return $form->schema([
            TextInput::make('name')->required()->maxLength(60)->placeholder('GST 5%')
                ->unique('tax_rates', 'name', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('entity_id', app(TenantContext::class)->entityId())),
            Select::make('gst_rate_bp')->label('GST rate')->options($slabs)->required(),
            TextInput::make('cess_rate_bp')->label('Cess (basis points, 100 = 1%)')->integer()->minValue(0)->maxValue(10000)->default(0),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('gst_rate_bp')->label('GST')->formatStateUsing(fn (int $state) => ($state / 100).'%'),
                TextColumn::make('cess_rate_bp')->label('Cess')->formatStateUsing(fn (int $state) => ($state / 100).'%'),
                TextColumn::make('status')->badge(),
                static::updatedColumn(),
            ])
            ->defaultSort('gst_rate_bp')
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTaxRates::route('/')];
    }
}

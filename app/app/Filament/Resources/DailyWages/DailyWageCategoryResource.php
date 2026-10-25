<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyWages;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\DailyWages\DailyWageCategoryResource\Pages;
use App\Filament\Support\Units;
use App\Models\DailyWages\DailyWageCategory;
use App\Support\Values\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Kinds of casual work and their rate: loading per day, bag stitching per bag, cleaning per day… */
class DailyWageCategoryResource extends Resource
{
    protected static ?string $model = DailyWageCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Daily wages';

    protected static ?string $navigationLabel = 'Work categories';

    protected static ?string $modelLabel = 'work category';

    protected static ?string $slug = 'daily-wage-categories';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('name')->required()->maxLength(120)->placeholder('e.g. Loading / unloading'),
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Za-z][A-Za-z0-9_\-]{1,19}$/')->placeholder('e.g. LOADING')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('entity_id', app(TenantContext::class)->entityId())),
            Select::make('unit')->label('Paid')->required()->default('day')
                ->options(collect(DailyWageCategory::UNITS)->map(fn ($u) => ucfirst($u[1]))->all()),
            TextInput::make('rate_paise')->label('Rate (Rs)')->required()->regex(Units::DECIMAL_2)
                ->formatStateUsing(fn ($state) => $state === null ? null : bcdiv((string) $state, '100', 2))
                ->dehydrateStateUsing(fn ($state) => Units::rupeesToPaise((string) $state)),
            Select::make('status')->options(['active' => 'In use', 'inactive' => 'Not in use'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('rate_paise')->label('Rate')->alignEnd()
                    ->formatStateUsing(fn (int $state, DailyWageCategory $r) => Money::paise($state)->format().' '.$r->rateLabel()),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->filters([SelectFilter::make('status')->options(['active' => 'In use', 'inactive' => 'Not in use'])->default('active')])
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDailyWageCategories::route('/')];
    }
}

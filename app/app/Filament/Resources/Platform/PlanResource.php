<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform;

use App\Domain\Saas\Modules;
use App\Filament\Resources\Platform\PlanResource\Pages;
use App\Filament\Support\Units;
use App\Models\Customer;
use App\Models\LoginAccount;
use App\Models\Plan;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS plans (platform admin only): the modules a customer may use, its limits — mills, staff users,
 * vehicles a month; empty is unlimited — and the monthly price.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Plans';

    protected static ?string $slug = 'platform/plans';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false;   // a plan in use is made inactive, never removed
    }

    public static function form(Form $form): Form
    {
        $limit = fn (string $name, string $label) => TextInput::make($name)->label($label)->integer()->minValue(1)
            ->placeholder('Unlimited')->helperText('Empty: unlimited');

        return $form->columns(2)->schema([
            Section::make('Plan')->columns(3)->schema([
                TextInput::make('code')->required()->maxLength(20)->regex('/^[a-z0-9_-]+$/')->unique(ignoreRecord: true)->disabledOn('edit'),
                TextInput::make('name')->required()->maxLength(80),
                TextInput::make('price')->label('Price a month (Rs)')->required()->regex(Units::DECIMAL_2)
                    ->afterStateHydrated(fn (TextInput $component, ?Plan $record) => $component->state($record ? number_format($record->price_paise_monthly / 100, 2, '.', '') : null)),
                Textarea::make('description')->maxLength(500)->columnSpanFull(),
                TextInput::make('sort')->label('Order')->integer()->default(100),
                Toggle::make('active')->label('Offered to customers')->default(true),
            ]),
            Section::make('Limits')->columns(3)->schema([
                $limit('max_mills', 'Mills'),
                $limit('max_users', 'Staff users'),
                $limit('max_vehicles_month', 'Vehicles a month'),
            ]),
            Section::make('Modules')->schema([
                CheckboxList::make('modules')->hiddenLabel()->options(Modules::labels())->columns(2)->required()
                    ->helperText('Masters, administration, security, dashboard and reports and the Office Desk are in every plan.'),
            ]),
        ]);
    }

    /**
     * The price typed in rupees is stored in paise.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prices(array $data): array
    {
        $data['price_paise_monthly'] = Units::rupeesToPaise((string) ($data['price'] ?? '0'));
        unset($data['price']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->description(fn (Plan $r) => $r->code)->searchable(),
                TextColumn::make('price_paise_monthly')->label('Price / month')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state)),
                TextColumn::make('max_mills')->label('Mills')->placeholder('Unlimited'),
                TextColumn::make('max_users')->label('Users')->placeholder('Unlimited'),
                TextColumn::make('max_vehicles_month')->label('Vehicles / month')->placeholder('Unlimited'),
                TextColumn::make('modules')->badge()->formatStateUsing(fn (string $state) => Modules::labels()[$state] ?? $state),
                TextColumn::make('customers')->label('Customers')->state(fn (Plan $r) => Customer::query()->where('plan_id', $r->id)->count()),
                TextColumn::make('active')->badge()->formatStateUsing(fn (bool $state) => $state ? 'offered' : 'not offered')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->actions([EditAction::make()->mutateFormDataUsing(fn (array $data) => self::prices($data))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePlans::route('/')];
    }
}

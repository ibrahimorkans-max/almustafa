<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform;

use App\Domain\Audit\AuditLogger;
use App\Domain\Saas\DataVisibility;
use App\Filament\Resources\Platform\DataVisibilityRuleResource\Pages;
use App\Models\Customer;
use App\Models\DataVisibilityRule;
use App\Models\LoginAccount;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform → Data visibility (product owner): how far back each customer sees its own records, by plan
 * (default) or per customer, for all modules or one; access end with grace; extend and override. Every
 * change is in the audit log. Records are never deleted for it (DataVisibility).
 */
class DataVisibilityRuleResource extends Resource
{
    protected static ?string $model = DataVisibilityRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-eye-slash';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Data visibility';

    protected static ?string $modelLabel = 'visibility rule';

    protected static ?string $slug = 'platform/data-visibility';

    protected static ?int $navigationSort = 32;

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
        return static::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('customer_id')->label('Customer / tenant')->searchable()->live()
                ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id'))
                ->helperText('Empty: the default for every customer on the plan below.')
                ->required(fn (Get $get) => blank($get('plan_id'))),
            Select::make('plan_id')->label('Subscription plan')->options(fn () => Plan::query()->orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => blank($get('customer_id')))->required(fn (Get $get) => blank($get('customer_id'))),
            Select::make('module')->options(DataVisibility::MODULES)->default('all')->required(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            TextInput::make('duration')->label('Visibility duration')->integer()->minValue(1)->maxValue(1200)
                ->helperText('How far back records are shown. Empty: no limit on age.'),
            Select::make('duration_unit')->label('Duration unit')->options(['days' => 'Days', 'months' => 'Months'])->default('days')->required(),
            DatePicker::make('starts_on')->label('Start date')->helperText('The rule applies from this day. Empty: at once.'),
            DatePicker::make('expires_on')->label('Expiry date')->helperText('After this day (and the grace days) nothing of the module is shown until extended. Empty: no end.'),
            TextInput::make('grace_days')->label('Grace period (days)')->integer()->minValue(0)->maxValue(365)->default(0),
            DatePicker::make('override_until')->label('Manual override until')->helperText('Everything is shown until this day, whatever the rule says.'),
            TextInput::make('override_reason')->label('Override reason')->maxLength(300)->required(fn (Get $get) => filled($get('override_until'))),
            Placeholder::make('note')->hiddenLabel()->columnSpanFull()
                ->content('Records are never deleted: hidden records show again as soon as the window is extended. Balances and totals stay complete.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'plan']))
            ->columns([
                TextColumn::make('applies')->label('Applies to')->state(fn (DataVisibilityRule $r) => $r->customer->name ?? ('Plan: '.($r->plan->name ?? '?')))
                    ->description(fn (DataVisibilityRule $r) => $r->customer_id === null ? 'Default for the plan' : 'This customer'),
                TextColumn::make('module')->formatStateUsing(fn (string $state) => DataVisibility::MODULES[$state] ?? $state),
                TextColumn::make('window')->label('Visible')->state(fn (DataVisibilityRule $r) => $r->windowLabel()),
                TextColumn::make('starts_on')->label('Start')->date('d M Y')->placeholder('—'),
                TextColumn::make('expires_on')->label('Expiry')->date('d M Y')->placeholder('No end')
                    ->description(fn (DataVisibilityRule $r) => $r->grace_days > 0 ? '+'.$r->grace_days.' days grace' : null),
                TextColumn::make('effect')->label('Today')->badge()
                    ->state(function (DataVisibilityRule $r): string {
                        $limit = DataVisibility::evaluate($r, CarbonImmutable::now('Asia/Karachi'));

                        return match (true) {
                            $limit === null => 'Everything shown',
                            $limit === DataVisibility::NONE => 'Access ended',
                            default => 'From '.$limit->format('d M Y'),
                        };
                    })
                    ->color(fn (string $state) => match (true) {
                        $state === 'Access ended' => 'danger', $state === 'Everything shown' => 'success', default => 'warning',
                    }),
                TextColumn::make('override_until')->label('Override')->date('d M Y')->placeholder('—')->description(fn (DataVisibilityRule $r) => $r->override_reason),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('module')->options(DataVisibility::MODULES),
                SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive']),
            ])
            ->actions([
                Action::make('extend')->label('Extend')->icon('heroicon-o-calendar-days')
                    ->form([
                        TextInput::make('days')->label('Extend the expiry by (days)')->integer()->minValue(1)->maxValue(3650)->required()->default(30),
                    ])
                    ->action(function (DataVisibilityRule $record, array $data): void {
                        $base = $record->expires_on !== null && $record->expires_on->isFuture() ? CarbonImmutable::parse($record->expires_on) : CarbonImmutable::now('Asia/Karachi');
                        $before = $record->expires_on?->toDateString();
                        $record->update(['expires_on' => $base->addDays((int) $data['days'])->toDateString()]);
                        app(AuditLogger::class)->record('platform.visibility.extended', $record, ['expires_on' => $before], ['expires_on' => $record->expires_on?->toDateString()]);
                        Notification::make()->success()->title('Extended to '.$record->expires_on?->format('d M Y').'.')->send();
                    }),
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('restore')->label('Restore full access')->icon('heroicon-o-lock-open')->color('success')
                        ->requiresConfirmation()->modalDescription('Switches the rule off: the customer sees all its records again.')
                        ->action(function (DataVisibilityRule $record): void {
                            $record->update(['status' => 'inactive']);
                            Notification::make()->success()->title('Full access restored.')->send();
                        }),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDataVisibilityRules::route('/')];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform;

use App\Domain\Audit\AuditLogger;
use App\Domain\Saas\Billing;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Saas\PlanLimits;
use App\Filament\Resources\Platform\CustomerResource\Pages;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform admin's customers (SaaS): every company using the system, its plan, its trial and its
 * usage. New customers are opened here (or sign up themselves); a trial is extended, an account
 * activated once paid, or paused — its people are signed out and can't sign in until it is reactivated.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $slug = 'platform/customers';

    protected static ?int $navigationSort = 10;

    public const STATUSES = [
        Customer::TRIAL => 'Free trial',
        Customer::ACTIVE => 'Active',
        Customer::PAST_DUE => 'Payment due (read-only)',
        Customer::SUSPENDED => 'Blocked',
        Customer::CANCELLED => 'Closed',
    ];

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

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false;   // a customer is closed, never removed: its records stay
    }

    /** @return array<string, string> */
    public static function planOptions(): array
    {
        return Plan::query()->orderBy('sort')->pluck('name', 'id')->all();
    }

    public const LIFETIME_NOTE = 'Active for good: no trial end, no more invoices, never read-only or paused for a bill. You can still pause it yourself.';

    /** @return list<Component> */
    public static function lifetimeForm(): array
    {
        return [
            TextInput::make('note')->label('Why (kept in the history)')->required()->maxLength(300)->placeholder('Founding customer'),
            Toggle::make('cancel_open')->label('Cancel its open invoices')->default(true),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function giveLifetime(Customer $customer, array $data): void
    {
        SaasInvoiceResource::run(fn (LoginAccount $by) => app(Billing::class)->grantLifetime($customer, $by, (string) $data['note'], (bool) ($data['cancel_open'] ?? false)),
            "{$customer->name} has lifetime access.");
    }

    /** @param  array<string, mixed>  $data */
    public static function endLifetime(Customer $customer, array $data): void
    {
        SaasInvoiceResource::run(fn (LoginAccount $by) => app(Billing::class)->revokeLifetime($customer, $by, (string) $data['note']),
            "Lifetime access ended for {$customer->name}.");
    }

    public const TRIAL_NOTE = 'A free trial of any length for this company and all its mills, from today. Its people use the whole plan until it ends; then they see only the payment page.';

    public static function canSetTrial(Customer $customer): bool
    {
        return ! $customer->isLifetime() && $customer->status !== Customer::CANCELLED && ! self::holdsPlatform($customer);
    }

    /** @return list<Component> */
    public static function trialForm(): array
    {
        return [TextInput::make('days')->label('Trial days from today')->integer()->minValue(1)->maxValue(365)
            ->default((int) config('ricemill.saas.trial_days', 14))->required()->suffix('days')];
    }

    /** @param  array<string, mixed>  $data */
    public static function setTrial(Customer $customer, array $data): void
    {
        $days = (int) $data['days'];
        $customer->update(['status' => Customer::TRIAL, 'trial_ends_at' => now('Asia/Karachi')->addDays($days)->endOfDay()->utc()]);
        app(AuditLogger::class)->record('saas.customer.trial_set', $customer, null, null, ['days' => $days]);
        app(CustomerAccess::class)->forget();
        Notification::make()->success()->title("{$customer->name}: free trial of {$days} days, until {$customer->trial_ends_at?->timezone('Asia/Karachi')->format('d M Y')}.")->send();
    }

    /** The platform's own company: never paused or closed from here. */
    public static function holdsPlatform(Customer $customer): bool
    {
        return LoginAccount::query()->where('is_platform_admin', true)
            ->whereIn('entity_id', Entity::query()->where('customer_id', $customer->id)->select('id'))->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Section::make('Company')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160),
                TextInput::make('contact_name')->label('Contact person')->maxLength(120),
                TextInput::make('phone')->tel()->maxLength(20),
                TextInput::make('email')->email()->maxLength(160),
                Textarea::make('address')->maxLength(500),
                Textarea::make('notes')->maxLength(2000),
            ]),
            Section::make('Subscription')->columns(3)->schema([
                Select::make('plan_id')->label('Plan')->options(fn () => self::planOptions())->placeholder('No plan: everything'),
                Select::make('status')->options(self::STATUSES)->required()->native(false)
                    ->disableOptionWhen(fn (string $value, ?Customer $record) => $record !== null && in_array($value, [Customer::SUSPENDED, Customer::CANCELLED], true) && self::holdsPlatform($record))
                    ->helperText('Paused or closed: its people are signed out and cannot sign in.'),
                DateTimePicker::make('trial_ends_at')->label('Trial ends')->seconds(false)->visible(fn ($get) => $get('status') === Customer::TRIAL),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $usage = fn (Customer $r, string $key) => app(PlanLimits::class)->usage($r->id)[$key];

        return $table
            ->defaultSort('code')
            ->recordUrl(fn (Customer $r) => static::getUrl('view', ['record' => $r]))
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->description(fn (Customer $r) => trim(($r->contact_name ?? '').' '.($r->phone ?? '')))->searchable()->wrap(),
                TextColumn::make('plan.name')->label('Plan')->placeholder('Everything'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state, Customer $r) => match (true) {
                        $r->isLifetime() && $state === Customer::ACTIVE => 'Lifetime',
                        $r->trialEnded() => 'Trial ended',
                        $state === Customer::TRIAL => 'Trial · '.$r->trialDaysLeft().' days left',
                        default => self::STATUSES[$state] ?? $state,
                    })
                    ->color(fn (string $state, Customer $r) => match (true) {
                        $r->isLifetime() && $state === Customer::ACTIVE => 'primary',
                        $r->trialEnded() => 'danger',
                        $state === Customer::ACTIVE => 'success',
                        $state === Customer::TRIAL => 'info',
                        $state === Customer::PAST_DUE => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('mills')->label('Mills')->state(fn (Customer $r) => $usage($r, 'mills')),
                TextColumn::make('users')->label('Users')->state(fn (Customer $r) => $usage($r, 'users')),
                TextColumn::make('vehicles')->label('Vehicles this month')->state(fn (Customer $r) => $usage($r, 'vehicles')),
                TextColumn::make('created_at')->label('Since')->date('d M Y')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(self::STATUSES)])
            ->actions([
                // Block / Unblock right on the row: a blocked company sees only the payment page and nothing of its data.
                Action::make('suspend')->label('Block')->icon('heroicon-o-no-symbol')->color('danger')->button()->size('sm')
                    ->visible(fn (Customer $r) => ! in_array($r->status, [Customer::SUSPENDED, Customer::CANCELLED], true) && ! self::holdsPlatform($r))
                    ->modalHeading(fn (Customer $r) => "Block {$r->name}?")
                    ->modalDescription('Its people are sent to the payment page at once and see none of their data, on the web, the app, shared links and messages, until you unblock it. Nothing is deleted.')
                    ->modalSubmitActionLabel('Block')
                    ->form([TextInput::make('reason')->label('Why (kept in the history)')->required()->minLength(5)->maxLength(300)])
                    ->action(function (Customer $r, array $data): void {
                        $r->update(['status' => Customer::SUSPENDED]);
                        app(AuditLogger::class)->record('saas.customer.paused', $r, null, null, ['reason' => $data['reason']]);
                        app(CustomerAccess::class)->forget();
                        Notification::make()->success()->title("{$r->name} is blocked.")->send();
                    }),
                Action::make('unblock')->label('Unblock')->icon('heroicon-o-lock-open')->color('success')->button()->size('sm')
                    ->visible(fn (Customer $r) => $r->status === Customer::SUSPENDED)
                    ->requiresConfirmation()->modalHeading(fn (Customer $r) => "Unblock {$r->name}?")
                    ->modalDescription('Its people can sign in and work again at once.')->modalSubmitActionLabel('Unblock')
                    ->action(function (Customer $r): void {
                        $r->update(['status' => Customer::ACTIVE, 'trial_ends_at' => null]);
                        app(AuditLogger::class)->record('saas.customer.unblocked', $r);
                        app(CustomerAccess::class)->forget();
                        Notification::make()->success()->title("{$r->name} is unblocked.")->send();
                    }),
                EditAction::make()->after(fn () => app(PlanLimits::class)->forget()),
                ActionGroup::make([
                    Action::make('extendTrial')->label('Set trial period')->icon('heroicon-o-clock')
                        ->visible(fn (Customer $r) => self::canSetTrial($r))
                        ->modalDescription(self::TRIAL_NOTE)->form(self::trialForm())
                        ->action(fn (Customer $r, array $data) => self::setTrial($r, $data)),
                    Action::make('activate')->label('Activate (paid)')->icon('heroicon-o-check-circle')->color('success')
                        ->visible(fn (Customer $r) => ! in_array($r->status, [Customer::ACTIVE, Customer::SUSPENDED], true))   // blocked: Unblock
                        ->modalDescription('Its people can sign in and use their plan in full.')
                        ->form([Toggle::make('invoice')->label('Issue this month\'s invoice now')->default(fn (Customer $r) => $r->status === Customer::TRIAL)])
                        ->action(function (Customer $r, array $data): void {
                            $r->update(['status' => Customer::ACTIVE, 'trial_ends_at' => null]);
                            $invoice = ($data['invoice'] ?? false) ? app(Billing::class)->issueMonthly($r->refresh(), CarbonImmutable::now('Asia/Karachi')) : null;
                            Notification::make()->success()->title("{$r->name} is active.".($invoice ? " Invoice {$invoice->number} issued." : ''))->send();
                        }),
                    Action::make('lifetime')->label('Give lifetime access')->icon('heroicon-o-sparkles')->color('primary')
                        ->visible(fn (Customer $r) => ! $r->isLifetime() && ! self::holdsPlatform($r))
                        ->modalDescription(self::LIFETIME_NOTE)->form(self::lifetimeForm())
                        ->action(fn (Customer $r, array $data) => self::giveLifetime($r, $data)),
                    Action::make('endLifetime')->label('End lifetime access')->icon('heroicon-o-x-mark')->color('gray')
                        ->visible(fn (Customer $r) => $r->isLifetime())
                        ->modalDescription('It stays active and is billed again from the next monthly run.')
                        ->form([TextInput::make('note')->label('Why')->required()->maxLength(300)])
                        ->action(fn (Customer $r, array $data) => self::endLifetime($r, $data)),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCustomers::route('/'), 'view' => Pages\ViewCustomer::route('/{record}')];
    }
}

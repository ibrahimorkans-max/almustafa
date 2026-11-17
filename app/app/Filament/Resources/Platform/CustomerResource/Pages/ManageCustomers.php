<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\CustomerResource\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Saas\PaymentPage;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Platform\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ManageCustomers extends ManageRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('several')->label(__('New customers (several)'))->icon('heroicon-o-squares-plus')->color('gray')
                ->modalHeading(__('New customers'))->modalWidth('7xl')->modalSubmitActionLabel(__('Open the accounts'))
                ->modalDescription(__('One row per customer: each gets its company, first mill (named after it) and Owner sign-in with a temporary password to change at first sign-in. The alias is your own name for the account; only you see it.'))
                ->form([
                    Section::make(__('For all of them'))->columns(3)->schema([
                        Select::make('plan_id')->label(__('Plan'))->options(fn () => CustomerResource::planOptions())->required(),
                        Select::make('status')->label(__('Start as'))->options([Customer::TRIAL => __('Free trial'), Customer::ACTIVE => __('Active (paid)')])
                            ->default(Customer::TRIAL)->required()->live(),
                        TextInput::make('trial_days')->label(__('Trial days'))->integer()->minValue(1)->maxValue(365)->suffix('days')->required()
                            ->default((int) config('ricemill.saas.trial_days', 14))->visible(fn (Get $get) => $get('status') === Customer::TRIAL),
                    ]),
                    Repeater::make('accounts')->label(__('Accounts'))->minItems(1)->maxItems(50)->defaultItems(2)->addActionLabel(__('Add another account'))
                        ->columns(6)->reorderable(false)
                        ->schema([
                            TextInput::make('alias')->label(__('Alias (only you)'))->maxLength(120),
                            TextInput::make('company')->label(__('Company name'))->required()->maxLength(160),
                            TextInput::make('phone')->tel()->required()->maxLength(20),
                            TextInput::make('owner_name')->label(__('Owner\'s name'))->required()->maxLength(120),
                            TextInput::make('username')->required()->regex('/^[a-z0-9._-]{3,64}$/')->distinct()
                                ->validationMessages(['regex' => '3 to 64 small letters, numbers, dots, dashes or underscores.']),
                            TextInput::make('password')->label(__('Temporary password'))->required()->rule(Password::defaults())
                                ->default(fn () => Str::password(14, symbols: false)),
                        ]),
                ])
                ->action(function (array $data): void {
                    $opened = [];
                    $failed = [];
                    foreach ((array) ($data['accounts'] ?? []) as $row) {
                        try {
                            $r = app(CustomerOnboarding::class)->open($row + ['plan_id' => $data['plan_id'], 'status' => $data['status'],
                                'trial_days' => $data['trial_days'] ?? null, 'must_change_password' => true], 'platform');
                            $opened[] = "{$r['customer']->code} {$r['customer']->name}".(filled($row['alias'] ?? null) ? " ({$row['alias']})" : '')." — {$r['owner']->username}";
                        } catch (BusinessRuleViolation $e) {
                            $failed[] = ($row['company'] ?? '?').': '.$e->getMessage();
                        }
                    }
                    Notification::make()->persistent()->{$failed === [] ? 'success' : 'warning'}()
                        ->title(count($opened).' account(s) opened'.($failed !== [] ? ', '.count($failed).' not opened' : '').'.')
                        ->body(implode("\n", [...$opened, ...array_map(fn ($f) => 'Not opened: '.$f, $failed)]))->send();
                }),
            Action::make('paymentPage')->label(__('Payment page'))->icon('heroicon-o-credit-card')->color('gray')
                ->modalHeading(__('Who sees the payment page ("My subscription")?'))
                ->modalDescription(__('Its plan, price, lifetime price, invoices and ways to pay. When it is hidden, the customer is not reminded of invoices; a blocked or read-only company is still told so.'))
                ->fillForm(fn () => ['mode' => PaymentPage::mode()])
                ->form([Radio::make('mode')->label(__('Show the payment page'))->options(\App\Support\Locales::options(PaymentPage::MODES))->required()
                    ->helperText(__('For "selected", tick "Payment page" on each customer in the list, or choose customers and use "Show / Hide the payment page".'))])
                ->action(function (array $data): void {
                    PaymentPage::setMode((string) $data['mode']);
                    app(AuditLogger::class)->record('saas.payment_page.mode', null, null, null, ['mode' => $data['mode']]);
                    Notification::make()->success()->title('Payment page: '.mb_strtolower(PaymentPage::MODES[$data['mode']]).'.')->send();
                }),
            Action::make('open')->label(__('New customer'))->icon('heroicon-o-plus')
                ->modalHeading(__('New customer'))->modalSubmitActionLabel(__('Open account'))
                ->form([
                    Section::make(__('Company'))->columns(2)->schema([
                        TextInput::make('company')->label(__('Company name'))->required()->maxLength(160),
                        TextInput::make('alias')->label(__('Alias (only you see it)'))->maxLength(120),
                        TextInput::make('contact_name')->label(__('Contact person'))->maxLength(120),
                        TextInput::make('phone')->tel()->required()->maxLength(20),
                        TextInput::make('email')->email()->maxLength(160),
                    ]),
                    Section::make(__('Subscription'))->columns(3)->schema([
                        Select::make('plan_id')->label(__('Plan'))->options(fn () => CustomerResource::planOptions())->required(),
                        Select::make('status')->label(__('Start as'))->options([Customer::TRIAL => __('Free trial'), Customer::ACTIVE => __('Active (paid)')])
                            ->default(Customer::TRIAL)->required()->live(),
                        TextInput::make('trial_days')->label(__('Trial days'))->integer()->minValue(1)->maxValue(365)->suffix('days')->required()
                            ->default((int) config('ricemill.saas.trial_days', 14))->visible(fn (Get $get) => $get('status') === Customer::TRIAL),
                    ]),
                    Section::make(__('First mill'))->columns(3)->schema([
                        TextInput::make('mill_name')->label(__('Mill name'))->helperText(__('Empty: the company name'))->maxLength(160),
                        TextInput::make('mill_code')->label(__('Short code'))->helperText(__('Empty: made from the name'))->maxLength(10)->regex('/^[A-Za-z0-9]+$/'),
                        TextInput::make('city')->maxLength(80),
                    ]),
                    Section::make(__('Owner sign-in'))->columns(3)->schema([
                        TextInput::make('owner_name')->label(__('Owner\'s full name'))->required()->maxLength(120),
                        TextInput::make('username')->required()->regex('/^[a-z0-9._-]{3,64}$/')->validationMessages(['regex' => 'Use 3 to 64 small letters, numbers, dots, dashes or underscores.']),
                        TextInput::make('password')->label(__('Temporary password'))->required()->rule(Password::defaults())
                            ->default(fn () => Str::password(14, symbols: false))
                            ->helperText(__('Give it to the owner; it must be changed at first sign-in.')),
                    ]),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $opened = app(CustomerOnboarding::class)->open($data + ['must_change_password' => true], 'platform');
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->success()->persistent()
                        ->title(__(':code :name is open.', ['code' => $opened['customer']->code, 'name' => $opened['customer']->name]))
                        ->body(__('Mill :code. Owner signs in as :username with the temporary password you set.', ['code' => $opened['mill']->code, 'username' => $opened['owner']->username]))
                        ->send();
                }),
        ];
    }
}

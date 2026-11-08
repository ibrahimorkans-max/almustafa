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
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('several')->label('New customers (several)')->icon('heroicon-o-squares-plus')->color('gray')
                ->modalHeading('New customers')->modalWidth('7xl')->modalSubmitActionLabel('Open the accounts')
                ->modalDescription('One row per customer: each gets its company, first mill (named after it) and Owner sign-in with a temporary password to change at first sign-in. The alias is your own name for the account; only you see it.')
                ->form([
                    Section::make('For all of them')->columns(3)->schema([
                        Select::make('plan_id')->label('Plan')->options(fn () => CustomerResource::planOptions())->required(),
                        Select::make('status')->label('Start as')->options([Customer::TRIAL => 'Free trial', Customer::ACTIVE => 'Active (paid)'])
                            ->default(Customer::TRIAL)->required()->live(),
                        TextInput::make('trial_days')->label('Trial days')->integer()->minValue(1)->maxValue(365)->suffix('days')->required()
                            ->default((int) config('ricemill.saas.trial_days', 14))->visible(fn (Get $get) => $get('status') === Customer::TRIAL),
                    ]),
                    Repeater::make('accounts')->label('Accounts')->minItems(1)->maxItems(50)->defaultItems(2)->addActionLabel('Add another account')
                        ->columns(6)->reorderable(false)
                        ->schema([
                            TextInput::make('alias')->label('Alias (only you)')->maxLength(120),
                            TextInput::make('company')->label('Company name')->required()->maxLength(160),
                            TextInput::make('phone')->tel()->required()->maxLength(20),
                            TextInput::make('owner_name')->label('Owner\'s name')->required()->maxLength(120),
                            TextInput::make('username')->required()->regex('/^[a-z0-9._-]{3,64}$/')->distinct()
                                ->validationMessages(['regex' => '3 to 64 small letters, numbers, dots, dashes or underscores.']),
                            TextInput::make('password')->label('Temporary password')->required()->rule(Password::defaults())
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
            Action::make('paymentPage')->label('Payment page')->icon('heroicon-o-credit-card')->color('gray')
                ->modalHeading('Who sees the payment page ("My subscription")?')
                ->modalDescription('Its plan, price, lifetime price, invoices and ways to pay. When it is hidden, the customer is not reminded of invoices; a blocked or read-only company is still told so.')
                ->fillForm(fn () => ['mode' => PaymentPage::mode()])
                ->form([Radio::make('mode')->label('Show the payment page')->options(PaymentPage::MODES)->required()
                    ->helperText('For "selected", tick "Payment page" on each customer in the list, or choose customers and use "Show / Hide the payment page".')])
                ->action(function (array $data): void {
                    PaymentPage::setMode((string) $data['mode']);
                    app(AuditLogger::class)->record('saas.payment_page.mode', null, null, null, ['mode' => $data['mode']]);
                    Notification::make()->success()->title('Payment page: '.mb_strtolower(PaymentPage::MODES[$data['mode']]).'.')->send();
                }),
            Action::make('open')->label('New customer')->icon('heroicon-o-plus')
                ->modalHeading('New customer')->modalSubmitActionLabel('Open account')
                ->form([
                    Section::make('Company')->columns(2)->schema([
                        TextInput::make('company')->label('Company name')->required()->maxLength(160),
                        TextInput::make('alias')->label('Alias (only you see it)')->maxLength(120),
                        TextInput::make('contact_name')->label('Contact person')->maxLength(120),
                        TextInput::make('phone')->tel()->required()->maxLength(20),
                        TextInput::make('email')->email()->maxLength(160),
                    ]),
                    Section::make('Subscription')->columns(3)->schema([
                        Select::make('plan_id')->label('Plan')->options(fn () => CustomerResource::planOptions())->required(),
                        Select::make('status')->label('Start as')->options([Customer::TRIAL => 'Free trial', Customer::ACTIVE => 'Active (paid)'])
                            ->default(Customer::TRIAL)->required()->live(),
                        TextInput::make('trial_days')->label('Trial days')->integer()->minValue(1)->maxValue(365)->suffix('days')->required()
                            ->default((int) config('ricemill.saas.trial_days', 14))->visible(fn (Get $get) => $get('status') === Customer::TRIAL),
                    ]),
                    Section::make('First mill')->columns(3)->schema([
                        TextInput::make('mill_name')->label('Mill name')->helperText('Empty: the company name')->maxLength(160),
                        TextInput::make('mill_code')->label('Short code')->helperText('Empty: made from the name')->maxLength(10)->regex('/^[A-Za-z0-9]+$/'),
                        TextInput::make('city')->maxLength(80),
                    ]),
                    Section::make('Owner sign-in')->columns(3)->schema([
                        TextInput::make('owner_name')->label('Owner\'s full name')->required()->maxLength(120),
                        TextInput::make('username')->required()->regex('/^[a-z0-9._-]{3,64}$/')->validationMessages(['regex' => 'Use 3 to 64 small letters, numbers, dots, dashes or underscores.']),
                        TextInput::make('password')->label('Temporary password')->required()->rule(Password::defaults())
                            ->default(fn () => Str::password(14, symbols: false))
                            ->helperText('Give it to the owner; it must be changed at first sign-in.'),
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
                        ->title("{$opened['customer']->code} {$opened['customer']->name} is open.")
                        ->body("Mill {$opened['mill']->code}. Owner signs in as {$opened['owner']->username} with the temporary password you set.")
                        ->send();
                }),
        ];
    }
}

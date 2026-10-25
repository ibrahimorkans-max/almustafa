<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\CustomerResource\Pages;

use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Platform\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
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
            Action::make('open')->label('New customer')->icon('heroicon-o-plus')
                ->modalHeading('New customer')->modalSubmitActionLabel('Open account')
                ->form([
                    Section::make('Company')->columns(2)->schema([
                        TextInput::make('company')->label('Company name')->required()->maxLength(160),
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

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\CustomerResource\Pages;

use App\Domain\Access\EntityProvisioner;
use App\Domain\Audit\AuditLogger;
use App\Domain\Saas\Billing;
use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Saas\PlanLimits;
use App\Domain\Saas\PlatformStats;
use App\Domain\Security\SignInArea;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Platform\CustomerResource;
use App\Filament\Resources\Platform\SaasInvoiceResource;
use App\Filament\Support\Units;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * One customer on one page (platform console): its company and subscription, usage against its plan,
 * its mills, its Owners and when they last signed in, its invoices and what happened to it.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected static string $view = 'filament.pages.platform.customer';

    public function getTitle(): string
    {
        return $this->customer()->name.' ('.$this->customer()->code.')';
    }

    public function customer(): Customer
    {
        /** @var Customer $record */
        $record = $this->getRecord();

        return $record;
    }

    /** @return list<string> the names of its mills with "Only inside the mill" on */
    public function areaMills(): array
    {
        $area = app(SignInArea::class);

        return app(TenantContext::class)->runAsSystem(fn () => Entity::query()->where('customer_id', $this->customer()->id)->get()
            ->filter(fn (Entity $e) => $area->isOn($e->id))->pluck('name')->values()->all());
    }

    /** @return array<string, mixed> */
    public function picture(): array
    {
        return app(PlatformStats::class)->customer($this->customer());
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->after(fn () => app(PlanLimits::class)->forget()),
            // If a mill locked itself out ("Only inside the mill" with a wrong site), the product owner lifts it.
            Action::make('areaOff')->label('Lift "Only inside the mill"')->icon('heroicon-o-map-pin')->color('warning')
                ->visible(fn () => $this->areaMills() !== [])
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Its people can sign in from anywhere again, at: '.implode(', ', $this->areaMills()).'. The mill can switch it back on under Security → Sign-in rules.')
                ->action(function (): void {
                    $by = Filament::auth()->user();
                    abort_unless($by instanceof LoginAccount && $by->isPlatformAdmin(), 403);
                    foreach (app(TenantContext::class)->runAsSystem(fn () => Entity::query()->where('customer_id', $this->customer()->id)->pluck('id')) as $id) {
                        app(TenantContext::class)->runAsSystem(fn () => app(SettingsService::class)->put(SettingsService::SIGN_IN_AREA, 'off', $id));
                    }
                    app(AuditLogger::class)->record('saas.customer.sign_in_area_lifted', $this->customer());
                    Notification::make()->success()->title('"Only inside the mill" is off for all its mills.')->send();
                }),
            Action::make('setTrial')->label('Set trial period')->icon('heroicon-o-clock')->color('gray')
                ->visible(fn () => CustomerResource::canSetTrial($this->customer()))
                ->modalDescription(CustomerResource::TRIAL_NOTE)->form(CustomerResource::trialForm())
                ->action(fn (array $data) => CustomerResource::setTrial($this->customer(), $data)),
            Action::make('lifetime')->label('Give lifetime access')->icon('heroicon-o-sparkles')->color('primary')
                ->visible(fn () => ! $this->customer()->isLifetime() && ! CustomerResource::holdsPlatform($this->customer()))
                ->modalDescription(CustomerResource::LIFETIME_NOTE)->form(CustomerResource::lifetimeForm())
                ->action(fn (array $data) => CustomerResource::giveLifetime($this->customer(), $data)),
            Action::make('endLifetime')->label('End lifetime access')->icon('heroicon-o-x-mark')->color('gray')
                ->visible(fn () => $this->customer()->isLifetime())
                ->form([TextInput::make('note')->label('Why')->required()->maxLength(300)])
                ->action(fn (array $data) => CustomerResource::endLifetime($this->customer(), $data)),
            Action::make('addMill')->label('Add a mill')->icon('heroicon-o-building-storefront')->color('gray')
                ->modalDescription('A new mill for this customer, with its roles, chart of accounts, HR and tax defaults. Its Owners see it at once.')
                ->form([
                    TextInput::make('name')->label('Mill name')->required()->maxLength(160),
                    TextInput::make('code')->label('Short code')->helperText('Empty: made from the name')->maxLength(10)->regex('/^[A-Za-z0-9]+$/'),
                    TextInput::make('city')->maxLength(80),
                    TextInput::make('province')->maxLength(40)->default('Punjab'),
                    TextInput::make('ntn')->label('NTN')->maxLength(20)
                        ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => PakistanIds::isValidNtn((string) PakistanIds::digits((string) $value)) ? null : $fail('NTN: 7 digits and the check digit (1234567-8), or the CNIC for an individual.'))
                        ->dehydrateStateUsing(fn (?string $state) => PakistanIds::digits($state)),
                    TextInput::make('strn')->label('STRN (only if registered for sales tax)')->maxLength(20)
                        ->rule(fn () => fn (string $attribute, mixed $value, \Closure $fail) => PakistanIds::isValidStrn((string) PakistanIds::digits((string) $value)) ? null : $fail('STRN: 13 digits.'))
                        ->dehydrateStateUsing(fn (?string $state) => PakistanIds::digits($state)),
                ])
                ->action(fn (array $data) => SaasInvoiceResource::run(function (LoginAccount $by) use ($data): void {
                    abort_unless($by->isPlatformAdmin(), 403);
                    $customer = $this->customer();
                    app(TenantContext::class)->runAsSystem(fn () => app(EntityProvisioner::class)->create([
                        'customer_id' => $customer->id,
                        'code' => app(CustomerOnboarding::class)->millCode($data['code'] ?? null, (string) $data['name']),
                        'name' => $data['name'], 'legal_name' => $data['name'], 'city' => $data['city'] ?? null, 'province' => $data['province'] ?? null,
                        'ntn' => $data['ntn'] ?? null, 'strn' => $data['strn'] ?? null, 'status' => Entity::STATUS_ACTIVE,
                    ]));
                }, 'Mill added.')),
            Action::make('newInvoice')->label('New invoice')->icon('heroicon-o-plus')->color('gray')
                ->form([
                    TextInput::make('description')->required()->maxLength(200)->placeholder('Setup and training'),
                    TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
                    TextInput::make('due_days')->label('Due in days')->integer()->minValue(0)->maxValue(90)
                        ->default((int) config('ricemill.saas.billing.due_days'))->required(),
                ])
                ->action(fn (array $data) => SaasInvoiceResource::run(function (LoginAccount $by) use ($data): void {
                    abort_unless($by->isPlatformAdmin(), 403);
                    app(Billing::class)->issue($this->customer(), (string) $data['description'],
                        Units::rupeesToPaise((string) $data['amount']), dueDays: (int) $data['due_days']);
                }, 'Invoice issued.')),
        ];
    }
}

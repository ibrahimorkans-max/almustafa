<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Security\DuressService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Owner / Super Admin / Accountant: set or remove their own duress PIN.
 *
 * @property Form $form
 */
class DuressPin extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'duress-pin';

    protected static ?string $title = 'Duress PIN';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.duress-pin';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->mayUseDuressPin();
    }

    public function mount(): void
    {
        $a = $this->account();
        $this->form->fill(['suspend' => $a->duress_suspends, 'wipe_device' => $a->duress_wipes_device]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('current_password')->password()->required()->autocomplete('current-password'),
            TextInput::make('pin')->label('Duress PIN (6–8 digits)')->password()->required()->regex('/^\d{6,8}$/')
                ->confirmed()->autocomplete('off'),
            TextInput::make('pin_confirmation')->label('Repeat the PIN')->password()->required()->autocomplete('off'),
            Toggle::make('suspend')->label('Also suspend my account when the PIN is used')->default(true),
            Toggle::make('wipe_device')->label('Also wipe the app data on the device it is used on (phone / PWA)'),
        ])->statePath('data');
    }

    public function hasPin(): bool
    {
        return $this->account()->duress_pin_hash !== null;
    }

    public function save(): void
    {
        $data = $this->form->getState();
        try {
            app(DuressService::class)->setPin($this->account(), (string) $data['current_password'], (string) $data['pin'],
                (bool) $data['suspend'], (bool) $data['wipe_device']);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $this->form->fill(['suspend' => $data['suspend'], 'wipe_device' => $data['wipe_device']]);
        Notification::make()->title('Duress PIN saved.')->success()->send();
    }

    public function remove(): void
    {
        try {
            app(DuressService::class)->removePin($this->account(), (string) ($this->data['current_password'] ?? ''));
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('Duress PIN removed.')->success()->send();
    }

    private function account(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }
}

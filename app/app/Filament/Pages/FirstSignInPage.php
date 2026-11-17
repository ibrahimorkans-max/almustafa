<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Auth\FirstSignIn;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Concerns\TranslatesLabels;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * A new user's first sign-in (App\Domain\Auth\FirstSignIn): shows where the sign-in came from, sends the e-mail with
 * the verification button and waits for it (the page moves on by itself), then asks for the user's own details.
 * Nothing else opens until it is done (EnsureWebAuthSession).
 *
 * @property Form $emailForm
 * @property Form $detailsForm
 */
class FirstSignInPage extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesLabels;

    protected static ?string $slug = 'first-sign-in';

    protected static ?string $title = 'Welcome — finish signing in';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.first-sign-in';

    /** @var array<string, mixed>|null */
    public ?array $emailData = [];

    /** @var array<string, mixed>|null */
    public ?array $detailsData = [];

    public bool $emailSent = false;

    public function mount(): void
    {
        $account = $this->account();
        if (! $account->needsFirstSignIn()) {
            $this->redirect(Filament::getUrl());

            return;
        }
        $this->emailSent = $account->email_verify_sent_at !== null && $account->email_verified_at === null;
        $this->emailForm->fill(['email' => $account->email ?? $account->person?->email]);
        $this->detailsForm->fill(['full_name' => $account->person?->full_name, 'phone' => PakistanIds::formatMobile($account->person?->phone) ?? $account->person?->phone]);
    }

    /** @return list<string> */
    protected function getForms(): array
    {
        return ['emailForm', 'detailsForm'];
    }

    public function emailForm(Form $form): Form
    {
        return $form->statePath('emailData')->schema([
            TextInput::make('email')->label(__('Your e-mail address'))->email()->required()->maxLength(191)->autocomplete('email')
                ->helperText(__('An e-mail with a button is sent here. Press it (on this computer or your phone) to confirm the address.')),
        ]);
    }

    public function detailsForm(Form $form): Form
    {
        return $form->statePath('detailsData')->columns(['default' => 1, 'sm' => 2])->schema([
            TextInput::make('full_name')->label(__('Full name'))->required()->minLength(3)->maxLength(150)->autocomplete('name'),
            TextInput::make('phone')->label(__('Mobile number'))->tel()->required()->maxLength(20)->placeholder('0300-1234567')->autocomplete('tel'),
        ]);
    }

    public function account(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }

    public function verified(): bool
    {
        return $this->account()->email_verified_at !== null;
    }

    /** @return array<string, mixed> where the first sign-in came from */
    public function info(): array
    {
        return $this->account()->first_sign_in ?? [];
    }

    public function sendEmail(): void
    {
        $email = (string) ($this->emailForm->getState()['email'] ?? '');
        try {
            app(FirstSignIn::class)->sendVerification($this->account(), $email);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->emailSent = true;
        Notification::make()->success()->title(__('E-mail sent to :email.', ['email' => FirstSignIn::masked($email)]))
            ->body(__('Open it and press "Verify my e-mail". This page moves on by itself.'))->send();
    }

    /** Asked every few seconds while the e-mail is waiting: the page moves on once the button was pressed. */
    public function checkVerified(): void
    {
        if ($this->verified()) {
            $this->emailSent = false;
        }
    }

    /** The browser's own position, sent by the page once it is allowed. */
    public function saveLocation(mixed $lat, mixed $lng, mixed $accuracy = null): void
    {
        app(FirstSignIn::class)->addLocation($this->account(), $lat, $lng, $accuracy);
    }

    public function saveDetails(): void
    {
        $data = $this->detailsForm->getState();
        try {
            $sessionId = session()->get(EnsureWebAuthSession::SESSION_KEY);
            $session = $sessionId === null ? null : app(TenantContext::class)->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->find($sessionId));
            app(FirstSignIn::class)->complete($this->account(), ['full_name' => (string) $data['full_name'], 'phone' => (string) $data['phone']],
                $session?->login_account_id === $this->account()->id ? $session : null);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        Notification::make()->success()->title(__('Welcome! You are signed in.'))->send();
        $this->redirect(Filament::getUrl());
    }
}

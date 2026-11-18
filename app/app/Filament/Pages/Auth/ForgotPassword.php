<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Auth\PasswordReset;
use App\Domain\Shared\BusinessRuleViolation;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot your password?" (App\Domain\Auth\PasswordReset): only the SaaS owner, Owners and Super Admins, with a code
 * sent to the login's own e-mail; everybody else is told to ask their administrator. The page never shows
 * whether a username exists or may reset.
 *
 * @property Form $requestForm
 * @property Form $resetForm
 */
class ForgotPassword extends SimplePage implements HasForms
{
    use InteractsWithForms;

    private const BROWSER = 'rm_password_reset_browser';

    protected static string $view = 'filament.pages.auth.forgot-password';

    /** @var array<string, mixed>|null */
    public ?array $requestData = [];

    /** @var array<string, mixed>|null */
    public ?array $resetData = [];

    public ?string $username = null;

    public bool $done = false;

    /** The 6-box code being typed (x-verify.otp). */
    public string $otp = '';

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            // A plain redirect: Livewire's own one cannot leave a page while it is first opened.
            throw new HttpResponseException(new RedirectResponse(Filament::getUrl()));
        }
        abort_unless(app(PasswordReset::class)->enabled(), 404);
        $this->requestForm->fill();
        $this->resetForm->fill();
    }

    public function getTitle(): string
    {
        return __('Reset your password');
    }

    /** @return list<string> */
    protected function getForms(): array
    {
        return ['requestForm', 'resetForm'];
    }

    public function requestForm(Form $form): Form
    {
        return $form->statePath('requestData')->schema([
            TextInput::make('username')->label(__('Username'))->required()->maxLength(64)->autocomplete('username')->autofocus(),
        ]);
    }

    public function resetForm(Form $form): Form
    {
        return $form->statePath('resetData')->schema([
            TextInput::make('password')->label(__('New password'))->password()->revealable()->required()
                ->rule(Password::defaults())->confirmed()->autocomplete('new-password'),
            TextInput::make('password_confirmation')->label(__('Repeat new password'))->password()->required()->autocomplete('new-password'),
        ]);
    }

    public function sendCode(): void
    {
        $username = (string) ($this->requestForm->getState()['username'] ?? '');
        try {
            app(PasswordReset::class)->request($username, $this->browser(), request()->ip());
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['requestData.username' => __($e->getMessage())]);
        }
        $this->username = mb_strtolower(trim($username));
    }

    public function resetPassword(): void
    {
        $data = $this->resetForm->getState();
        if (strlen(preg_replace('/\D/', '', $this->otp) ?? '') !== 6) {
            Notification::make()->title(__('Type the 6-digit code from the e-mail.'))->danger()->send();

            return;
        }
        try {
            app(PasswordReset::class)->reset((string) $this->username, $this->otp, (string) $data['password'], $this->browser(), request()->ip());
        } catch (BusinessRuleViolation $e) {
            $this->otp = '';
            $this->dispatch('rmv-otp-invalid');   // the boxes shake and clear
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->dispatch('rmv-otp-valid');
        session()->forget(self::BROWSER);
        $this->done = true;
    }

    public function startOver(): void
    {
        $this->username = null;
        $this->resetForm->fill();
    }

    /** This browser's own token: the code works only here. */
    private function browser(): string
    {
        $token = session()->get(self::BROWSER);
        if (! is_string($token)) {
            $token = (string) Str::uuid();
            session()->put(self::BROWSER, $token);
        }

        return $token;
    }
}

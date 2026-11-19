<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Auth\PasswordReset;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Concerns\ProvesSecondFactor;
use App\Models\LoginAccount;
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
    use ProvesSecondFactor;

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

    /** @var list<int> after the code: the four numbers to pick the e-mail's 2-digit number from */
    public array $choices = [];

    /** The number was right: the person's own second factor, then the new password. */
    public bool $numberOk = false;

    public bool $factorOk = false;

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

    /** Step 1: the 6-digit code. */
    public function checkCode(): void
    {
        if (strlen(preg_replace('/\D/', '', $this->otp) ?? '') !== 6) {
            Notification::make()->title(__('Type the 6-digit code from the e-mail.'))->danger()->send();

            return;
        }
        try {
            $this->choices = app(PasswordReset::class)->checkCode((string) $this->username, $this->otp, $this->browser());
        } catch (BusinessRuleViolation $e) {
            $this->otp = '';
            $this->dispatch('rmv-otp-invalid');   // the boxes shake and clear
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->otp = '';
        $this->dispatch('rmv-otp-valid');
    }

    /** Step 2: the 2-digit number of the e-mail. Wrong: start again with a new e-mail. */
    public function pickNumber(int $number): void
    {
        try {
            app(PasswordReset::class)->pickNumber((string) $this->username, $number, $this->browser());
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();
            $this->startOver();

            return;
        }
        $this->numberOk = true;
        $this->factorOk = ! app(PasswordReset::class)->factorNeeded((string) $this->username, $this->browser());
    }

    /** Step 3: the person's own second factor (ProvesSecondFactor). */
    protected function factorAccount(): LoginAccount
    {
        return app(PasswordReset::class)->factorAccount((string) $this->username, $this->browser());
    }

    protected function factorKey(): string
    {
        return $this->browser();
    }

    protected function factorProven(): void
    {
        $this->factorOk = true;
    }

    /** Step 4: the new password. */
    public function resetPassword(): void
    {
        $data = $this->resetForm->getState();
        try {
            app(PasswordReset::class)->reset((string) $this->username, (string) $data['password'], $this->browser(), request()->ip());
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        session()->forget(self::BROWSER);
        $this->done = true;
    }

    public function startOver(): void
    {
        $this->username = null;
        $this->choices = [];
        $this->numberOk = false;
        $this->factorOk = false;
        $this->otp = '';
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

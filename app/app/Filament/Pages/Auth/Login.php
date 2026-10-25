<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\PasskeyService;
use App\Domain\Security\SignInArea;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-step sign-in: username + password, then a TOTP/recovery code when the account has 2FA; or one
 * step with a passkey (fingerprint, face or device PIN), which counts as both factors.
 * Accounts whose role requires 2FA but have not enrolled are signed in with no permissions
 * and confined to the 2FA setup page (see EnsureWebAuthSession).
 *
 * Which account passed step one is kept in the server-side session, never in Livewire state.
 */
class Login extends BaseLogin
{
    private const PENDING_KEY = 'login.pending';

    private const PENDING_TTL_SECONDS = 300;

    public const DEVICE_COOKIE = 'rm_device';

    public bool $awaitingCode = false;

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('username')
                ->label('Username')
                ->required(fn () => ! $this->awaitingCode)
                ->hidden(fn () => $this->awaitingCode)
                ->autocomplete('username')
                ->autofocus()
                ->maxLength(64),
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->required(fn () => ! $this->awaitingCode)
                ->hidden(fn () => $this->awaitingCode)
                ->autocomplete('current-password')
                ->maxLength(256),
            // The 6-digit code: six boxes, sent by itself when the sixth digit is typed.
            ViewField::make('code')
                ->hiddenLabel()
                ->view('filament.forms.otp-boxes')
                ->visible(fn () => $this->awaitingCode && ! $this->useRecoveryCode),
            TextInput::make('recovery_code')
                ->label('Recovery code')
                ->helperText('One of the recovery codes you saved when you set up two-step sign-in.')
                ->required(fn () => $this->awaitingCode && $this->useRecoveryCode)
                ->visible(fn () => $this->awaitingCode && $this->useRecoveryCode)
                ->autocomplete('off')
                ->maxLength(32),
            ViewField::make('code_links')->hiddenLabel()->view('filament.forms.code-links')->dehydrated(false)->visible(fn () => $this->awaitingCode),
            // Where the device is, for "Only inside the mill"; asked for only when the mill needs it.
            Hidden::make('latitude'),
            Hidden::make('longitude'),
            Hidden::make('accuracy'),
            ViewField::make('location_probe')->hiddenLabel()->view('filament.forms.location-probe')->dehydrated(false),
        ])->statePath('data');
    }

    public bool $useRecoveryCode = false;

    public function toggleRecoveryCode(): void
    {
        $this->useRecoveryCode = ! $this->useRecoveryCode;
        $this->data['code'] = null;
        $this->data['recovery_code'] = null;
        $this->resetErrorBag();
    }

    public function getHeading(): string|Htmlable
    {
        return $this->awaitingCode ? 'Two-step verification' : parent::getHeading();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->awaitingCode) {
            return parent::getSubheading();
        }

        return $this->useRecoveryCode ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.';
    }

    /** @return array<int|string, Form|string> */
    protected function getForms(): array
    {
        return ['form' => $this->form($this->makeForm())];
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $login = app(LoginService::class);
        $errorField = $this->awaitingCode ? 'data.code' : 'data.username';

        try {
            if (! $this->awaitingCode) {
                $account = $login->verifyPassword((string) $data['username'], (string) $data['password'], request()->ip(), (string) request()->cookie(self::DEVICE_COOKIE) ?: null);
                $this->assertPanelAccess($account);
                $this->assertInsideArea($account);

                if ($login->mfaStep($account) === LoginService::MFA_CHALLENGE) {
                    session()->put(self::PENDING_KEY, ['id' => $account->id, 'at' => now()->timestamp]);
                    $this->awaitingCode = true;
                    $this->form->fill();

                    return null;
                }

                return $this->finish($account, false);
            }

            $account = $this->pendingAccount();
            $login->verifySecondFactor($account, (string) ($this->useRecoveryCode ? ($data['recovery_code'] ?? '') : ($data['code'] ?? '')));
            session()->forget(self::PENDING_KEY);

            return $this->finish($account, true);
        } catch (AuthenticationFailed $e) {
            if ($this->awaitingCode) {
                $this->data['code'] = null;   // empties the boxes for another try
                $errorField = $this->useRecoveryCode ? 'data.recovery_code' : 'data.code';
            }

            throw ValidationException::withMessages([$errorField => $e->publicMessage()]);
        }
    }

    /**
     * "Only inside the mill": the device's position must be inside the mill. Without one, the page asks the
     * browser for it (once) and sends the sign-in again by itself.
     *
     * @throws AuthenticationFailed
     */
    private function assertInsideArea(LoginAccount $account): void
    {
        $lat = $this->data['latitude'] ?? null;
        try {
            // A browser is never excused (no agent signature): its device cookie can be set by anyone.
            app(LoginService::class)->assertInsideArea($account, $lat, $this->data['longitude'] ?? null, $this->data['accuracy'] ?? null, request()->ip());
        } catch (AuthenticationFailed $e) {
            if ($e->reason === SignInArea::LOCATION_NEEDED && ! is_numeric($lat)) {
                $this->dispatch('rm-need-location');
            }

            throw $e;
        }
    }

    public function cancelCode(): void
    {
        session()->forget(self::PENDING_KEY);
        $this->awaitingCode = false;
        $this->useRecoveryCode = false;
        $this->form->fill();
    }

    /** Step 1 of a passkey sign-in: a one-time challenge for the device to sign (public/js/passkeys.js). */
    public function passkeyOptions(): string
    {
        return app(PasskeyService::class)->authenticationOptions();
    }

    /** Step 2: the signed challenge. The passkey names the account; the usual account checks follow. */
    public function passkeyLogin(string $credential): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }
        try {
            $account = app(PasskeyService::class)->authenticate(mb_substr($credential, 0, 20_000))
                ?? throw AuthenticationFailed::credentials('passkey_not_recognised');
            app(LoginService::class)->verifyPasskey($account, request()->ip(), (string) request()->cookie(self::DEVICE_COOKIE) ?: null);
            $this->assertPanelAccess($account);
            $this->assertInsideArea($account);
            session()->forget(self::PENDING_KEY);

            return $this->finish($account, true, 'passkey');
        } catch (AuthenticationFailed $e) {
            throw ValidationException::withMessages(['data.username' => $e->publicMessage()]);
        }
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            // On the code step the main button reads "Verify & continue" (the six boxes also send by themselves).
            ...array_map(fn (Action $a) => $a->label(fn () => $this->awaitingCode ? 'Verify & continue' : __('filament-panels::pages/auth/login.form.actions.authenticate.label')),
                parent::getFormActions()),
            Action::make('passkey')->label('Sign in with a passkey')->icon('heroicon-o-finger-print')->color('gray')->outlined()
                ->alpineClickHandler('rmPasskeyLogin($wire)')
                ->visible(fn () => ! $this->awaitingCode),
        ];
    }

    private function finish(LoginAccount $account, bool $mfaPassed, string $method = 'password'): LoginResponse
    {
        $session = app(LoginService::class)->completeLogin(
            $account,
            AuthSession::CHANNEL_WEB,
            $this->deviceInfo(),
            request()->ip(),
            request()->userAgent(),
            $mfaPassed,
            $method,
        );

        Filament::auth()->login($account);
        session()->regenerate();
        session()->forget(EnsureWebAuthSession::WORKING_ENTITY_KEY);
        session()->put(EnsureWebAuthSession::SESSION_KEY, $session->id);
        session()->put('password_hash_'.Filament::getAuthGuard(), $account->getAuthPassword());
        // The position the sign-in was made from starts the session's location ("Only inside the mill").
        if (is_numeric($this->data['latitude'] ?? null) && is_numeric($this->data['longitude'] ?? null)) {
            app(TenantContext::class)->runAsSystem(fn () => $session->forceFill([
                'latitude' => round((float) $this->data['latitude'], 6), 'longitude' => round((float) $this->data['longitude'], 6), 'location_at' => now(),
                'location_accuracy_m' => is_numeric($this->data['accuracy'] ?? null) ? (int) $this->data['accuracy'] : null, 'location_source' => 'browser',
            ])->save());
        }

        return app(LoginResponse::class);
    }

    /** @throws AuthenticationFailed */
    private function pendingAccount(): LoginAccount
    {
        $pending = session()->get(self::PENDING_KEY);
        if (! is_array($pending) || now()->timestamp - (int) $pending['at'] > self::PENDING_TTL_SECONDS) {
            $this->cancelCode();

            throw AuthenticationFailed::credentials('mfa_step_expired');
        }

        $account = app(TenantContext::class)->runAsSystem(
            fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->find($pending['id']),
        );
        if ($account === null || ! $account->isActive() || $account->isLocked()) {
            $this->cancelCode();

            throw AuthenticationFailed::credentials('mfa_step_account_unavailable');
        }

        app(AuthContextBinder::class)->bind($account);

        return $account;
    }

    /** @throws AuthenticationFailed */
    private function assertPanelAccess(LoginAccount $account): void
    {
        if (! $account->canAccessPanel(Filament::getCurrentPanel())) {
            app(AuditLogger::class)->record('auth.login.blocked', $account, meta: ['reason' => 'no_panel_access']);

            throw AuthenticationFailed::credentials('no_panel_access');
        }
    }

    /** A long-lived random cookie identifies this browser as a device. */
    private function deviceInfo(): DeviceInfo
    {
        $uid = request()->cookie(self::DEVICE_COOKIE);
        if (! is_string($uid) || ! Str::isUuid($uid)) {
            $uid = (string) Str::uuid();
        }
        Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $uid, 60 * 24 * 365 * 5, httpOnly: true, sameSite: 'strict'));

        return new DeviceInfo($uid, 'web', Str::limit((string) request()->userAgent(), 110, ''));
    }
}

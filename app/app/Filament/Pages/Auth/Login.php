<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\LoginService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-step sign-in: username + password, then a TOTP/recovery code when the account has 2FA.
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
            TextInput::make('code')
                ->label('Authentication code')
                ->helperText('6-digit code from your authenticator app, or a recovery code.')
                ->required(fn () => $this->awaitingCode)
                ->visible(fn () => $this->awaitingCode)
                ->autocomplete('one-time-code')
                ->maxLength(32),
        ])->statePath('data');
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
                $account = $login->verifyPassword((string) $data['username'], (string) $data['password'], request()->ip());
                $this->assertPanelAccess($account);

                if ($login->mfaStep($account) === LoginService::MFA_CHALLENGE) {
                    session()->put(self::PENDING_KEY, ['id' => $account->id, 'at' => now()->timestamp]);
                    $this->awaitingCode = true;
                    $this->form->fill();

                    return null;
                }

                return $this->finish($account, false);
            }

            $account = $this->pendingAccount();
            $login->verifySecondFactor($account, (string) $data['code']);
            session()->forget(self::PENDING_KEY);

            return $this->finish($account, true);
        } catch (AuthenticationFailed $e) {
            throw ValidationException::withMessages([$errorField => $e->publicMessage()]);
        }
    }

    public function cancelCode(): void
    {
        session()->forget(self::PENDING_KEY);
        $this->awaitingCode = false;
        $this->form->fill();
    }

    private function finish(LoginAccount $account, bool $mfaPassed): LoginResponse
    {
        $session = app(LoginService::class)->completeLogin(
            $account,
            AuthSession::CHANNEL_WEB,
            $this->deviceInfo(),
            request()->ip(),
            request()->userAgent(),
            $mfaPassed,
        );

        Filament::auth()->login($account);
        session()->regenerate();
        session()->forget(EnsureWebAuthSession::WORKING_ENTITY_KEY);
        session()->put(EnsureWebAuthSession::SESSION_KEY, $session->id);
        session()->put('password_hash_'.Filament::getAuthGuard(), $account->getAuthPassword());

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

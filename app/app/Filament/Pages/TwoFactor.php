<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\MfaService;
use App\Domain\Auth\SessionService;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Enroll TOTP (QR code + first code), view status, regenerate recovery codes.
 * The secret being enrolled lives only in the (encrypted) server session until confirmed.
 *
 * @property Form $form
 */
class TwoFactor extends Page implements HasForms
{
    use InteractsWithForms;

    private const ENROLL_SECRET_KEY = 'mfa.enroll_secret';

    protected static ?string $slug = 'two-factor';

    protected static ?string $title = 'Two-factor authentication';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.two-factor';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var list<string> Shown exactly once after enrollment/regeneration. */
    public array $recoveryCodes = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->label('Code from your authenticator app')->required()->maxLength(10)
                ->autocomplete('one-time-code'),
        ])->statePath('data');
    }

    public function account(): LoginAccount
    {
        /** @var LoginAccount */
        return Filament::auth()->user();
    }

    public function isEnrolled(): bool
    {
        return $this->account()->hasMfa();
    }

    public function isRequired(): bool
    {
        return $this->account()->mfaRequired();
    }

    public function qrSvg(): string
    {
        $mfa = app(MfaService::class);
        $secret = session()->get(self::ENROLL_SECRET_KEY);
        if (! is_string($secret)) {
            $secret = $mfa->generateSecret();
            session()->put(self::ENROLL_SECRET_KEY, $secret);
        }

        return $mfa->qrSvg($mfa->otpauthUrl($this->account(), $secret));
    }

    public function manualKey(): string
    {
        return chunk_split((string) session()->get(self::ENROLL_SECRET_KEY, ''), 4, ' ');
    }

    public function confirm(): void
    {
        $secret = session()->get(self::ENROLL_SECRET_KEY);
        if ($this->isEnrolled() || ! is_string($secret)) {
            return;
        }

        $codes = app(MfaService::class)->confirmEnrollment($this->account(), $secret, (string) $this->form->getState()['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['data.code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        session()->forget(self::ENROLL_SECRET_KEY);
        $session = app(ActorContext::class)->session();
        if ($session !== null) {
            app(SessionService::class)->markMfaPassed($session);
        }

        $this->recoveryCodes = $codes;
        $this->form->fill();
        Notification::make()->title('Two-factor authentication is on')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerate')
                ->label('New recovery codes')
                ->visible(fn () => $this->isEnrolled())
                ->requiresConfirmation()
                ->modalDescription('Your old recovery codes will stop working.')
                ->form([TextInput::make('code')->label('Current authentication code')->required()])
                ->action(function (array $data): void {
                    $mfa = app(MfaService::class);
                    $account = $this->account();
                    if (! $mfa->verifyCode($account, (string) $data['code'])) {
                        Notification::make()->title('Code not valid')->danger()->send();

                        return;
                    }
                    $this->recoveryCodes = $mfa->regenerateRecoveryCodes($account);
                    app(AuditLogger::class)->record('auth.mfa.recovery_codes_regenerated', $account);
                }),
        ];
    }
}

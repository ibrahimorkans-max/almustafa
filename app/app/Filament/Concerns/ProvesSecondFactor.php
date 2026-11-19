<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Auth\MobileCode;
use App\Domain\Auth\PasskeyService;
use App\Domain\Auth\SecondFactor;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\LoginAccount;
use Filament\Notifications\Notification;

/**
 * The "prove it is you" step of a sign-in check page (App\Domain\Auth\SecondFactor), shown by x-verify.second-factor:
 * the person's own passkey, authenticator code, recovery code or mobile code — nobody else is asked. The page gives the
 * login, the check's key and what happens once it is proven.
 */
trait ProvesSecondFactor
{
    /** The way chosen (passkey | totp | recovery | mobile); empty: the first one offered. */
    public string $factorMethod = '';

    /** The 6-box code (authenticator or mobile). */
    public string $factorCode = '';

    /** A recovery code as typed. */
    public string $factorText = '';

    public bool $factorMobileSent = false;

    abstract protected function factorAccount(): LoginAccount;

    abstract protected function factorKey(): string;

    /** Called once the second factor is proven: the page moves on. */
    abstract protected function factorProven(): void;

    /** @return list<string> */
    public function factorMethods(): array
    {
        try {
            return app(SecondFactor::class)->methods($this->factorAccount());
        } catch (BusinessRuleViolation) {
            return [];
        }
    }

    public function currentFactor(): string
    {
        $methods = $this->factorMethods();

        return in_array($this->factorMethod, $methods, true) ? $this->factorMethod : ($methods[0] ?? '');
    }

    public function chooseFactor(string $method): void
    {
        if (in_array($method, $this->factorMethods(), true)) {
            $this->factorMethod = $method;
            $this->factorCode = '';
            $this->factorText = '';
        }
    }

    public function maskedFactorMobile(): string
    {
        try {
            return app(MobileCode::class)->masked($this->factorAccount());
        } catch (BusinessRuleViolation) {
            return '';
        }
    }

    /** Step 1 of the passkey: a one-time challenge for the device to sign (public/js/passkeys.js, rmPasskeyVerify). */
    public function passkeyOptions(): string
    {
        return app(PasskeyService::class)->authenticationOptions();
    }

    /** Step 2: the signed challenge — it must be one of this login's passkeys. */
    public function provePasskey(string $credential): void
    {
        $this->runFactor(fn () => app(SecondFactor::class)->provePasskey($this->factorAccount(), $this->factorKey(), $credential));
    }

    /** The authenticator code (6 boxes) or a recovery code. */
    public function proveFactorCode(): void
    {
        $method = $this->currentFactor();
        $code = $method === 'recovery' ? $this->factorText : $this->factorCode;
        $this->runFactor(fn () => app(SecondFactor::class)->proveCode($this->factorAccount(), $this->factorKey(), $method, $code));
    }

    public function sendFactorMobile(): void
    {
        try {
            app(SecondFactor::class)->sendMobile($this->factorAccount(), $this->factorKey());
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->factorMobileSent = true;
        Notification::make()->success()->title(__('Code sent to your mobile :number.', ['number' => $this->maskedFactorMobile()]))->send();
    }

    public function proveFactorMobile(): void
    {
        $this->runFactor(fn () => app(SecondFactor::class)->proveMobile($this->factorAccount(), $this->factorKey(), $this->factorCode));
    }

    private function runFactor(callable $prove): void
    {
        try {
            $prove();
        } catch (BusinessRuleViolation $e) {
            $this->factorCode = '';
            $this->dispatch('rmv-otp-invalid');
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }
        $this->factorCode = '';
        $this->factorText = '';
        $this->dispatch('rmv-otp-valid');
        Notification::make()->success()->title(__('Verified: it is you.'))->send();
        $this->factorProven();
    }
}

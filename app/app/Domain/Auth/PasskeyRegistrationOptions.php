<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Webauthn\AuthenticatorSelectionCriteria;

/** New passkeys must be discoverable (sign-in without typing a username) and unlocked by fingerprint, face or PIN. */
final class PasskeyRegistrationOptions extends GeneratePasskeyRegisterOptionsAction
{
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return new AuthenticatorSelectionCriteria(
            null,
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }
}

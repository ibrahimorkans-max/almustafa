<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Sign-in challenge that demands user verification: the device must check the fingerprint, face or
 * PIN, not only that a key is present. That is what lets a passkey stand for both factors.
 */
final class PasskeyOptions extends GeneratePasskeyAuthenticationOptionsAction
{
    public function execute(): string
    {
        $options = Serializer::make()->toJson(new PublicKeyCredentialRequestOptions(
            challenge: Str::random(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        ));
        Session::put('passkey-authentication-options', $options);

        return $options;
    }
}

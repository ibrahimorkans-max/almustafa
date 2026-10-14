<?php

use App\Domain\Auth\PasskeyCeremony;
use App\Domain\Auth\PasskeyOptions;
use App\Domain\Auth\PasskeyRegistrationOptions;
use App\Models\LoginAccount;
use App\Models\Passkey;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;

/*
 * Passkeys (WebAuthn) for sign-in. The app calls the package's actions from its own sign-in flow
 * (App\Domain\Auth\PasskeyService); the package's routes and Livewire components are not used.
 *
 * The relying party id is the site's host name: a passkey made for erp.example.pk works only there.
 * Browsers refuse IP addresses, so locally use http://localhost:8000, not 127.0.0.1.
 */
return [
    'redirect_to_after_login' => '/admin',

    'actions' => [
        'generate_passkey_register_options' => PasskeyRegistrationOptions::class,
        'store_passkey' => StorePasskeyAction::class,
        'generate_passkey_authentication_options' => PasskeyOptions::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        'configure_ceremony_step_manager_factory' => PasskeyCeremony::class,
    ],

    'relying_party' => [
        'name' => env('RICEMILL_BRAND_NAME', 'Al Mustafa Rice Mills'),
        'id' => env('PASSKEYS_RP_ID', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
        'icon' => null,
    ],

    'models' => [
        'passkey' => Passkey::class,
        'authenticatable' => LoginAccount::class,
    ],
];

<?php

use App\Models\LoginAccount;

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'accounts',
    ],

    /*
    | web: Filament back office (session cookie, backed by auth_sessions).
    | api: opaque bearer tokens bound to a device (PWA, Flutter, gate agent).
    | Self-service password reset is deliberately not offered: accounts are reset
    | by an administrator and must change the temporary password at next login.
    */
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'accounts',
        ],
        'api' => [
            'driver' => 'ricemill-token',
            'provider' => 'accounts',
        ],
    ],

    'providers' => [
        'accounts' => [
            'driver' => 'tenant-eloquent',
            'model' => LoginAccount::class,
        ],
    ],

    'passwords' => [
        'accounts' => [
            'provider' => 'accounts',
            'table' => 'password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 900),

];

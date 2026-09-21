<?php

declare(strict_types=1);

use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

return [

    'defaults' => [
        // A literal, not env(): an unqualified auth check must never fall back to a session guard.
        'guard' => 'api',
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'api' => [
            'driver' => 'passport',
            'provider' => 'users',
        ],

        'merchant_web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            // A literal, not env(): authentication must stay on the Merchant module's user model.
            'model' => User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];

<?php

use App\Models\User;

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
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

    /*
    | Admin two-step sign-in: after the password, a 6-digit code is emailed
    | and must be entered to get a token. Off until the dashboard has the
    | code screen. An environment setting (not an admin setting), so an
    | admin cannot switch it off from the dashboard.
    */
    'admin_two_factor' => [
        'enabled' => (bool) env('ADMIN_TWO_FACTOR_ENABLED', false),
        'code_expiry_minutes' => (int) env('ADMIN_TWO_FACTOR_CODE_EXPIRY_MINUTES', 10),
        'max_attempts' => 5,
        'max_resends' => 3,
        'resend_cooldown_seconds' => 60,
    ],

];

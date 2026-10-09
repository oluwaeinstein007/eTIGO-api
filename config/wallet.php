<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */

    'currency' => env('WALLET_CURRENCY', 'NGN'),

    /*
    |--------------------------------------------------------------------------
    | Passenger Wallet Limits (in kobo)
    |--------------------------------------------------------------------------
    |
    | All amounts in kobo (100 kobo = ₦1). Integer arithmetic only.
    |
    */

    'min_topup' => (int) env('WALLET_MIN_TOPUP', 50000),          // ₦500

    'max_balance' => (int) env('WALLET_MAX_BALANCE', 50000000),    // ₦500,000

    'daily_topup_cap' => (int) env('WALLET_DAILY_TOPUP_CAP', 10000000), // ₦100,000

    /*
    |--------------------------------------------------------------------------
    | Hold Expiry
    |--------------------------------------------------------------------------
    */

    'hold_expiry_hours' => (int) env('WALLET_HOLD_EXPIRY_HOURS', 4),

    'abandoned_topup_minutes' => (int) env('WALLET_ABANDONED_TOPUP_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Driver Payouts (in kobo)
    |--------------------------------------------------------------------------
    */

    'min_payout' => (int) env('WALLET_MIN_PAYOUT', 100000),       // ₦1,000

    'settlement_delay' => env('WALLET_SETTLEMENT_DELAY', 'instant'), // instant|24h|weekly

    'max_negative_balance' => (int) env('WALLET_MAX_NEGATIVE_BALANCE', -500000), // -₦5,000

    /*
    |--------------------------------------------------------------------------
    | Commission
    |--------------------------------------------------------------------------
    */

    'default_commission_rate' => (float) env('WALLET_DEFAULT_COMMISSION_RATE', 0.2000), // 20%

    /*
    |--------------------------------------------------------------------------
    | Fraud Guardrails
    |--------------------------------------------------------------------------
    */

    'max_topups_per_hour' => (int) env('WALLET_MAX_TOPUPS_PER_HOUR', 5),

    'max_topups_per_day' => (int) env('WALLET_MAX_TOPUPS_PER_DAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Flutterwave Configuration (Wallet)
    |--------------------------------------------------------------------------
    |
    | Reuses the same Flutterwave keys as the ride-payment gateway.
    |
    */

    'flutterwave' => [
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'webhook_hash' => env('FLUTTERWAVE_WEBHOOK_HASH'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
    ],

];

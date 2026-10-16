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

    'max_cash_overpayment' => (int) env('WALLET_MAX_CASH_OVERPAYMENT', 200000), // ₦2,000

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
    | Payout Account Safeguards
    |--------------------------------------------------------------------------
    |
    | After a driver replaces their payout bank account, payouts to the new
    | account wait this many hours (0 = no hold), so a change the driver did
    | not make can be reported before money leaves. A first account is exempt
    | unless payout_account_hold_first_account is true.
    |
    | The account name Paystack returns is compared with the driver's verified
    | name. payout_account_name_check: off (record only), review (a mismatch
    | is saved but paid out only after finance approves it) or block (a
    | mismatched account cannot be saved).
    |
    */

    'payout_account_hold_hours' => (int) env('WALLET_PAYOUT_ACCOUNT_HOLD_HOURS', 24),

    'payout_account_hold_first_account' => (bool) env('WALLET_PAYOUT_ACCOUNT_HOLD_FIRST_ACCOUNT', false),

    'payout_account_name_check' => env('WALLET_PAYOUT_ACCOUNT_NAME_CHECK', 'review'),

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
    | Paystack Configuration (Wallet & Transfers)
    |--------------------------------------------------------------------------
    |
    | Reuses the same Paystack keys as the ride-payment gateway.
    |
    */

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

];

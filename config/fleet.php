<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily Reset Time (WAT)
    |--------------------------------------------------------------------------
    |
    | The time at which the daily remittance cycle resets. All rides completed
    | before this time are assigned to the previous day's remittance record.
    | Format: HH:MM in Africa/Lagos (WAT) timezone.
    |
    */

    'daily_reset_time' => env('FLEET_DAILY_RESET_TIME', '04:00'),

    /*
    |--------------------------------------------------------------------------
    | Shortfall Escalation Thresholds
    |--------------------------------------------------------------------------
    |
    | Consecutive shortfall days that trigger each escalation tier.
    |
    */

    'shortfall_warning_days' => (int) env('FLEET_SHORTFALL_WARNING_DAYS', 3),

    'shortfall_review_days' => (int) env('FLEET_SHORTFALL_REVIEW_DAYS', 7),

    'shortfall_escalation_days' => (int) env('FLEET_SHORTFALL_ESCALATION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Default Daily Remittance Target
    |--------------------------------------------------------------------------
    |
    | Fallback daily remittance target in Naira. Each agreement stores its own
    | target, so this is only used as a reference default.
    |
    */

    'default_daily_target' => (float) env('FLEET_DEFAULT_DAILY_TARGET', 40000),

];

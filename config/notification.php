<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    |
    | Default channels per notification category. Each notification type
    | belongs to a category that determines which channels it uses.
    | Users can override push/in_app via their preferences.
    |
    */

    'channels' => [
        'push' => env('NOTIFICATION_PUSH_ENABLED', true),
        'in_app' => env('NOTIFICATION_IN_APP_ENABLED', true),
        'mail' => env('NOTIFICATION_MAIL_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Category Defaults
    |--------------------------------------------------------------------------
    |
    | Whether each category is enabled by default for new users.
    | Users can override these via their notification preferences.
    |
    */

    'category_defaults' => [
        'ride_updates' => true,
        'safety' => true,
        'payments' => true,
        'promotions' => true,
        'compliance' => true,
        'gamification' => true,
        'ev_charging' => true,
        'account' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long to keep read/unread notifications before pruning.
    |
    */

    'retention_days' => env('NOTIFICATION_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Max push notifications per user within a rolling window to prevent
    | notification fatigue.
    |
    */

    'rate_limit' => [
        'max_per_hour' => env('NOTIFICATION_MAX_PER_HOUR', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => 20,

];

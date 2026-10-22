<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Sendchamp sends WhatsApp (approved templates only) with SMS fallback.
    // WhatsApp is skipped until both the sender number and the template code
    // for the message are set; SMS needs a Sendchamp-approved sender name.
    'sendchamp' => [
        'access_key' => env('SENDCHAMP_ACCESS_KEY'),
        'base_url' => env('SENDCHAMP_BASE_URL', 'https://api.sendchamp.com/api/v1'),
        'sender_name' => env('SENDCHAMP_SENDER_NAME', 'Sendchamp'),
        // dnd also reaches numbers on the NCC Do-Not-Disturb list, which OTPs need.
        // Numbers outside Nigeria always use the international route.
        'sms_route' => env('SENDCHAMP_SMS_ROUTE', 'dnd'),
        'whatsapp_sender' => env('SENDCHAMP_WHATSAPP_SENDER'),
        // Authentication template; body variable {{1}} is the code.
        'whatsapp_otp_template' => env('SENDCHAMP_WHATSAPP_OTP_TEMPLATE'),
        // Without an own template, codes can go through Sendchamp's verification
        // service instead, which sends from Flutterwave's WhatsApp account.
        'whatsapp_otp_via_verification' => (bool) env('SENDCHAMP_WHATSAPP_OTP_VIA_VERIFICATION', false),
        // Utility template; body variable {{1}} is the whole message.
        'whatsapp_template' => env('SENDCHAMP_WHATSAPP_TEMPLATE'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'places_api_key' => env('GOOGLE_PLACES_API_KEY', env('GOOGLE_MAPS_API_KEY')),
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY', env('GOOGLE_PLACES_API_KEY')),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect' => '',
    ],

    'mapbox' => [
        'access_token' => env('MAPBOX_ACCESS_TOKEN'),
        'public_token' => env('MAPBOX_PUBLIC_TOKEN'),
    ],

    'geoapify' => [
        'api_key' => env('GEOAPIFY_API_KEY'),
    ],

    'locationiq' => [
        'api_key' => env('LOCATIONIQ_API_KEY'),
    ],

    'places' => [
        'drivers' => env('PLACE_SEARCH_DRIVERS', 'google,mapbox,geoapify,locationiq,photon'),
        'country' => env('PLACE_SEARCH_COUNTRY', 'ng'),
    ],

    'demo_earnings_driver_user_id' => env('DEMO_EARNINGS_DRIVER_USER_ID'),

    'firebase' => [
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'fake' => filter_var(env('PAYSTACK_FAKE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'flutterwave' => [
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
        'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
    ],

    'qoreid' => [
        'client_id' => env('QOREID_CLIENT_ID'),
        'secret_key' => env('QOREID_SECRET_KEY'),
        'webhook_secret' => env('QOREID_WEBHOOK_SECRET'),
        'skip_verification' => filter_var(
            env('KYC_SKIP_VERIFICATION', env('QOREID_SKIP_VERIFICATION', false)),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    'postman' => [
        'api_key' => env('POSTMAN_API_KEY'),
        'collection_id' => env('POSTMAN_COLLECTION_ID'),
        'workspace_id' => env('POSTMAN_WORKSPACE_ID'),
    ],

];

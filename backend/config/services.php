<?php

return [
    'location_capture' => [
        'max_accuracy_meters' => env('LOCATION_CAPTURE_MAX_ACCURACY_METERS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    // Transactional customer reminders. Set SMS_DRIVER=twilio and the
    // remaining values in the production .env to enable actual SMS delivery.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'twilio_sid' => env('TWILIO_ACCOUNT_SID'),
        'twilio_token' => env('TWILIO_AUTH_TOKEN'),
        'twilio_from' => env('TWILIO_FROM_NUMBER'),
        'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '+63'),
    ],

    // Server-side only. Never expose PAYMONGO_SECRET_KEY to the frontend.
    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
    ],

    // Browser push is opt-in. The public key is returned only to an already
    // authenticated customer portal session; the private key never leaves the
    // server or application logs.
    'web_push' => [
        'enabled' => env('WEB_PUSH_ENABLED', false),
        'vapid_subject' => env('WEB_PUSH_VAPID_SUBJECT'),
        'vapid_public_key' => env('WEB_PUSH_VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY'),
        'currency_symbol' => env('WEB_PUSH_CURRENCY_SYMBOL', '₱'),
    ],

];

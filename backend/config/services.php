<?php

return [
    'location_capture' => [
        // GPS accuracy is an uncertainty radius, not router distance. The
        // service enforces a minimum acceptable threshold of 50 meters even
        // if an older production environment contains a stricter value.
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

    // Transactional SMS is sent through Semaphore only when an application flow
    // explicitly requests it. Daily billing reminders remain Web Push only.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'semaphore_api_key' => env('SEMAPHORE_API_KEY'),
        // Optional. When blank, Semaphore uses the account's registered default.
        'semaphore_sender_name' => env('SEMAPHORE_SENDER_NAME'),
        'semaphore_base_url' => env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co/api/v4'),
    ],

    'ip_intelligence' => [
        'base_url' => env('IPINFO_BASE_URL', 'https://api.ipinfo.io/lite'),
        'token' => env('IPINFO_TOKEN'),
    ],

    // Server-side only. Never expose PAYMONGO_SECRET_KEY to the frontend.
      'paymongo' => [
         'secret_key' => env('PAYMONGO_SECRET_KEY'),
         'public_key' => env('PAYMONGO_PUBLIC_KEY'),
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

    // Facebook Page + Messenger automation. Credentials are server-only;
    // the page token is encrypted in the database after an administrator
    // completes the Meta Page OAuth flow.
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'graph_version' => env('FACEBOOK_GRAPH_API_VERSION', 'v23.0'),
        'webhook_verify_token' => env('FACEBOOK_WEBHOOK_VERIFY_TOKEN'),
        'oauth_redirect_uri' => env('FACEBOOK_OAUTH_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/') . '/api/v1/integrations/facebook/callback'),
    ],

];

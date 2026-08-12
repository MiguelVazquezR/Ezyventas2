<?php

return [

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'google_ads' => [
        'id' => env('GOOGLE_ADS_ID'),
    ],

    'mercadopago' => [
        'client_id'         => env('MP_CLIENT_ID'),
        'client_secret'     => env('MP_CLIENT_SECRET'),
        'redirect_uri'      => env('MP_REDIRECT_URI'),
        'platform_token'    => env('MP_PLATFORM_ACCESS_TOKEN'),
        'test_access_token' => env('MP_TEST_ACCESS_TOKEN'),
        'webhook_secret'    => env('MP_WEBHOOK_SECRET'),
        'env'               => env('MP_ENV', 'sandbox'),
    ],

    'swsapien' => [
        'endpoint'               => env('SW_SAPIEN_ENDPOINT', 'https://services.test.sw.com.mx'),
        'token'                  => env('SW_SAPIEN_TOKEN'),
        'management_endpoint'    => env('SW_SAPIEN_MANAGEMENT_ENDPOINT'),
        'management_users_path'  => env('SW_SAPIEN_MANAGEMENT_USERS_PATH', '/management/v2/api/dealers/users'),
        'default_stamps'         => env('SW_SAPIEN_DEFAULT_STAMPS', 10),
        'mock'                   => env('SW_SAPIEN_MOCK', false),
        'low_balance_threshold'  => env('SW_SAPIEN_LOW_BALANCE_THRESHOLD', 500),
    ],

];

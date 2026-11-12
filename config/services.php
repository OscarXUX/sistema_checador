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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'hikvision' => [
        'url' => rtrim((string) env('HIKVISION_URL', 'http://192.168.0.69'), '/'),
        'username' => (string) env('HIKVISION_USER', 'admin'),
        'password' => (string) env('HIKVISION_PASSWORD', ''),
        'device_serial' => (string) env('HIKVISION_DEVICE_SERIAL', 'DS-K1T804AEF'),
        'timeout' => (int) env('HIKVISION_TIMEOUT', 10),
    ],

];

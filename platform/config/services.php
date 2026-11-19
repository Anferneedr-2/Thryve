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


    // Set by the installer. Reachability only until the Hermes contract is verified (plan D02).
    'hermes' => [
        'url' => env('HERMES_URL'),
        'key' => env('HERMES_API_KEY'),
        'model' => env('HERMES_MODEL', 'thryve-bot'), // the profile name Hermes serves, sent as "model" in each request
        'timeout' => (int) env('HERMES_TIMEOUT', 60), // seconds; Hermes retries a rate-limited provider for 10 minutes, so we never wait that long
    ],

];

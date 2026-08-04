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

    'bunnycdn' => [
        'storage_zone' => env('BUNNYCDN_STORAGE_ZONE'),
        'api_key' => env('BUNNYCDN_API_KEY'),
        'region' => env('BUNNYCDN_REGION', ''),
        'host' => env('BUNNYCDN_HOST', 'storage.bunnycdn.com'),
        'pull_zone_url' => env('BUNNYCDN_PULL_ZONE_URL'),
        'token_auth_key' => env('BUNNYCDN_TOKEN_AUTH_KEY'),
        // How long a signed (token-authenticated) URL stays valid. Default 7 days.
        'url_ttl' => (int) env('BUNNYCDN_URL_TTL', 604800),
        // Everything this app uploads lives under one prefix, because the
        // storage zone is shared with other projects.
        'prefix' => env('BUNNYCDN_PREFIX', 'cfo'),
    ],

];

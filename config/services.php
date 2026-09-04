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

    /*
    | Razorpay, for taking money at checkout.
    |
    | Leave the keys unset and the marketplace behaves as it always has: a
    | non-COD order is marked paid the moment it is placed. Set them and the
    | order waits for a real capture instead — nothing else has to change,
    | which is what makes this safe to switch on per environment.
    */
    'razorpay' => [
        'key' => env('RAZORPAY_KEY_ID'),
        'secret' => env('RAZORPAY_KEY_SECRET'),
        // Set separately in the Razorpay dashboard; without it the webhook
        // cannot be trusted and is refused rather than believed.
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'currency' => env('RAZORPAY_CURRENCY', 'INR'),
        // Kept under a typical gateway timeout, so an unreachable Razorpay
        // comes back as this app's own error rather than the proxy's.
        'connect_timeout' => (int) env('RAZORPAY_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('RAZORPAY_TIMEOUT', 20),
    ],

    /*
    | Text messages, which here means the sign-in code and nothing else.
    |
    | Leave `SMS_DRIVER` at `log` and codes go to the log and come back as
    | `debug_code` outside production — how the demo and the tests sign in.
    | Production must set `http` and a provider, or a shopper cannot get in.
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'key' => env('SMS_API_KEY'),
        'url' => env('SMS_URL', 'https://control.msg91.com/api/v5/flow'),
        'sender' => env('SMS_SENDER'),
        'template_id' => env('SMS_TEMPLATE_ID'),
        'country_code' => env('SMS_COUNTRY_CODE', '91'),
        'connect_timeout' => (int) env('SMS_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('SMS_TIMEOUT', 15),
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
        // Kept well under a typical 60s gateway timeout, so an unreachable
        // storage zone comes back as this app's own error rather than the
        // proxy's "Service is not reachable" page.
        'connect_timeout' => (int) env('BUNNYCDN_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('BUNNYCDN_TIMEOUT', 20),
    ],

];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Browser push
    |--------------------------------------------------------------------------
    |
    | Push is off until a VAPID key pair is configured. Generate one with
    | `php artisan webpush:vapid` and put the two values in the environment —
    | the public key is handed to the browser, the private key signs the
    | requests to the push service and must stay on the server.
    |
    | Browsers only allow push on HTTPS (localhost excepted), so this stays
    | dormant on a plain http:// deployment.
    |
    */

    'enabled' => (bool) env('VAPID_PUBLIC_KEY') && (bool) env('VAPID_PRIVATE_KEY'),

    'public_key' => env('VAPID_PUBLIC_KEY'),

    'private_key' => env('VAPID_PRIVATE_KEY'),

    // Contact address push services use if a delivery goes wrong.
    'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://localhost')),

    // How long the push service should hold a message for a browser that is
    // offline, in seconds. A day is plenty for an admin alert.
    'ttl' => (int) env('VAPID_TTL', 86400),
];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    |
    | Push for the seller app goes through FCM's HTTP v1 API, which is signed
    | with a service account rather than a plain server key. Point
    | FIREBASE_CREDENTIALS at the JSON Firebase gave you.
    |
    | Three forms are accepted, because a hosted deployment often cannot put a
    | file on disk:
    |
    |   a path      /var/www/html/storage/app/private/firebase/service-account.json
    |   raw JSON    {"type":"service_account", ...}
    |   base64      base64 of that same JSON  (easiest to paste into Dokploy)
    |
    | Everything stays dormant until the credentials parse — no credentials
    | means no queued jobs and no failed deliveries, just no push.
    |
    */

    // Left empty, it falls back to the file below — which is where the JSON
    // lives locally and inside the container alike.
    'credentials' => env('FIREBASE_CREDENTIALS') ?: storage_path('app/private/firebase/service-account.json'),

    // Normally read from the credentials; only set this to override.
    'project_id' => env('FIREBASE_PROJECT_ID'),

    // Lets you switch push off without removing the credentials.
    'enabled' => (bool) env('FIREBASE_PUSH_ENABLED', true),

    /*
    | How long a message may sit waiting for a phone that is offline. Matches
    | the web push TTL — a day is plenty for an admin alert.
    */
    'ttl' => (int) env('FIREBASE_TTL', 86400),

    /*
    | Android notification channel the app has created. Has to match the id
    | used in the app, or Android 8+ drops the notification silently.
    */
    'android_channel' => env('FIREBASE_ANDROID_CHANNEL', 'cfo_alerts'),
];

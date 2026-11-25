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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Error tracking: forwarded to Sentry only when the SDK is installed and this is set (plan Part D).
    'sentry_dsn' => env('SENTRY_LARAVEL_DSN'),
    // Sign in with Google on the phone: the Web client ID of the Firebase project (not a secret; the app is told it by app/version). Empty: no Google button.
    'google' => [
        'web_client_id' => env('GOOGLE_WEB_CLIENT_ID', ''),
        // Only if an app's phone asks Google for a token with another Web client ID (another Firebase project): those IDs,
        // comma-separated. Every app in the one project shares GOOGLE_WEB_CLIENT_ID and needs nothing here.
        'extra_client_ids' => env('GOOGLE_EXTRA_CLIENT_IDS', ''),
    ],
    // Push to the phones (FCM HTTP v1, App\Services\Notifications\PushSender): the path of the Firebase service-account JSON, kept
    // OUTSIDE the repo (this repo is public). Unset or unreadable: pushes are skipped (logged once), the inbox still fills.
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS', ''),
    ],

];

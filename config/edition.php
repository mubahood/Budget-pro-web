<?php

/*
| Which product this installation is (docs/STANDALONE_EDITION_PLAN.md).
|
| saas        many shops sign up and pay a subscription (budget-dynamics.schooldynamics.ug)
| standalone  one business, on the buyer's own server, with a licence instead of a subscription
|
| The edition only decides what SURROUNDS the shop: the public site, sign-up, trials, plans and
| billing, the platform screens, the demos. Selling, stock and money never ask which edition they
| are in. Written by the installer; never changed afterwards (EnsureInstalled refuses a mismatch).
| Both apps read this one file (budget-pro-new bridges it).
*/
return [
    'name' => env('APP_EDITION', 'saas'),

    // Standalone: the licence the installer saved, and the marker that says the copy is installed.
    'licence_path' => env('APP_LICENCE_PATH', dirname(__DIR__).'/storage/app/private/licence.json'),
    'installed_path' => dirname(__DIR__).'/storage/app/private/installed.json',

    // Standalone: the web app's folder (budget_pro_web), which the kernel caches, updates and backs up with itself.
    'web_path' => env('BUDGETPRO_WEB_PATH'),

    // Standalone: where to look for updates (the SaaS). Empty = upload only.
    'update_server' => env('APP_UPDATE_SERVER', 'https://budget-dynamics.schooldynamics.ug'),

    // The public keys this copy trusts for licences and updates. A release carries them in
    // RELEASE_KEYS.json beside artisan; on the SaaS there is none (it holds the secret instead).
    'public_keys' => (static function (): array {
        $file = dirname(__DIR__).'/RELEASE_KEYS.json';
        $keys = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($keys) ? $keys : [];
    })(),

    // SaaS only: the Ed25519 secret that signs releases and licences. Never in a release.
    'signing_key_id' => env('RELEASE_SIGNING_KEY_ID'),
    'signing_secret' => env('RELEASE_SIGNING_SECRET'),

    // SaaS only: the two repositories a release is built from, and Composer for the web app's vendor.
    'release_sources' => [
        'kernel' => env('RELEASE_KERNEL_PATH') ?: dirname(__DIR__),
        'web' => env('RELEASE_WEB_PATH') ?: dirname(__DIR__, 2).'/budget-pro-new',
    ],
    'composer_binary' => env('RELEASE_COMPOSER'),

    // SaaS only: the empty MySQL database a release's clean-install proof runs against (cPanel
    // accounts cannot CREATE DATABASE over SQL). Never the application's own database.
    'test_database' => [
        'host' => env('RELEASE_TEST_DB_HOST'),
        'port' => env('RELEASE_TEST_DB_PORT', '3306'),
        'database' => env('RELEASE_TEST_DB_DATABASE'),
        'user' => env('RELEASE_TEST_DB_USER'),
        'password' => env('RELEASE_TEST_DB_PASSWORD'),
        'socket' => env('RELEASE_TEST_DB_SOCKET'), // optional: a MySQL that only listens on a socket
    ],
];

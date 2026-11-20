<?php

/*
 * Promo videos (App\Models\PromoVideo): films made by the video studio for each app of the family.
 * The studio's worker signs in to /api/studio-api with a bearer token; only its SHA-256 is kept here, so a copy of
 * this server's .env cannot drive the studio API. Make one with:  php -r 'echo hash("sha256", "<the token>");'
 * With no hash set, the studio API answers 503 and nothing else changes.
 */
return [
    'token_sha256' => env('PROMO_STUDIO_TOKEN_SHA256'),

    // Files live in public storage (the `admin` disk: public/storage), where both web apps already serve media.
    'disk' => 'admin',
    'dir' => 'promo-videos',

    // Largest files the studio may send, in bytes.
    'max' => ['video' => 300 * 1048576, 'poster' => 10 * 1048576, 'sheet' => 25 * 1048576, 'captions' => 2 * 1048576],

    // The apps a film can belong to (budget-pro-new config/apps.php keys), for the admin's list.
    'apps' => [
        'pledge-and-budget' => 'Pledge & Budget', 'estate-ledger' => 'Estate Ledger', 'shelfie' => 'Shelfie',
        'kuku-tally' => 'Kuku Tally', 'akiba-ledger' => 'Akiba Ledger', 'budget-pro' => 'Budget Pro',
    ],

    // The app websites' public pages kept as files (budget-pro-new `site:static`, in its document root). Publishing,
    // taking down or editing a film removes them, so the sites show the change at once (PHP serves the pages until the
    // next deploy writes them again). The two apps sit side by side on the host; set it when the document root is elsewhere.
    'page_cache' => env('PROMO_VIDEOS_PAGE_CACHE', dirname(base_path()).'/budget-dynamics/page-cache'),

    // A render still "running" after this many minutes is failed, so the queue never sticks.
    'stale_minutes' => 90,
];

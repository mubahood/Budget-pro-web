<?php

/*
| Mobile app versions and legacy API retirement (plan B10 step 4, P4-8).
*/
return [
    // Apps older than this get 426 "update required" (sent as X-App-Version by app 2.0+).
    'min_version' => env('MOBILE_MIN_VERSION', '2.0.0'),
    'latest_version' => env('MOBILE_LATEST_VERSION', '2.0.0'),
    // Only for a request that names no app (App\Support\StoreLink): old phones that send nothing keep today's link.
    'store_url' => env('MOBILE_STORE_URL', 'https://play.google.com/store/apps/details?id=com.budget.dynamics'),
    // Each app's own listing, by AppFamily slug (the X-App header or the `app` field). '' means no listing: the phone is not
    // sent to another app's. com.budget.dynamics is UnityDesk's listing; Budget Pro keeps to the web. Unconfirmed packages stay
    // empty until set.
    'store_urls' => [
        'unitydesk' => env('MOBILE_STORE_URL_UNITYDESK', 'https://play.google.com/store/apps/details?id=com.budget.dynamics'),
        // Budget Pro's phones are on com.budget.dynamics until the switch day (unitydesk docs 07): then set MOBILE_STORE_URL_BUDGET_PRO= (empty) and MOBILE_STORE_URL to the web.
        'budget-pro' => env('MOBILE_STORE_URL_BUDGET_PRO', 'https://play.google.com/store/apps/details?id=com.budget.dynamics'),
        'family-roots' => env('MOBILE_STORE_URL_FAMILY_ROOTS', 'https://play.google.com/store/apps/details?id=musenene.com'),
        'pledge-and-budget' => env('MOBILE_STORE_URL_PLEDGE_BUDGET', 'https://play.google.com/store/apps/details?id=com.budgetpro.pledgeandbudget'),
        'shelfie' => env('MOBILE_STORE_URL_SHELFIE', ''),
        'kuku-tally' => env('MOBILE_STORE_URL_KUKU_TALLY', ''),
        'akiba-ledger' => env('MOBILE_STORE_URL_AKIBA', ''),
        'estate-ledger' => env('MOBILE_STORE_URL_ESTATE_LEDGER', ''),
    ],
    // The pre-v1 routes the old app uses. Turn off once `php artisan legacy:status` shows < 5 % of active
    // shops on the old app for 14 days (B10 step 4); they then answer "please update" instead of data.
    'legacy_enabled' => (bool) env('LEGACY_API_ENABLED', true),
    'legacy_retire_below_percent' => 5,
];

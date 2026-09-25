<?php

/*
| Mobile app versions and legacy API retirement (plan B10 step 4, P4-8).
*/
return [
    // Apps older than this get 426 "update required" (sent as X-App-Version by app 2.0+).
    'min_version' => env('MOBILE_MIN_VERSION', '2.0.0'),
    'latest_version' => env('MOBILE_LATEST_VERSION', '2.0.0'),
    'store_url' => env('MOBILE_STORE_URL', 'https://play.google.com/store/apps/details?id=com.budget.dynamics'),
    // The pre-v1 routes the old app uses. Turn off once `php artisan legacy:status` shows < 5 % of active
    // shops on the old app for 14 days (B10 step 4); they then answer "please update" instead of data.
    'legacy_enabled' => (bool) env('LEGACY_API_ENABLED', true),
    'legacy_retire_below_percent' => 5,
];

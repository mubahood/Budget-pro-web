<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trial length (days) for newly registered companies
    |--------------------------------------------------------------------------
    */
    'trial_days' => env('SAAS_TRIAL_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Default plan slug assigned to new sign-ups
    |--------------------------------------------------------------------------
    */
    'default_plan' => env('SAAS_DEFAULT_PLAN', 'trial'),

    /*
    | Days after a subscription/licence lapses during which the tenant keeps
    | read-only web access and devices keep selling + syncing (DECISIONS.md H5).
    */
    'grace_days' => env('SAAS_GRACE_DAYS', 7),

    // Outbound email (notifications) — off until a mailer is configured on the host.
    'mail_enabled' => (bool) env('SAAS_MAIL_ENABLED', false),

    // Timezone used when formatting dates for people (per-company timezones arrive in Phase 1).
    'display_timezone' => env('SAAS_DISPLAY_TIMEZONE', 'Africa/Kampala'),

    // Deep link the payment result page offers to return to the mobile app (null hides the button).
    'mobile_deep_link' => env('MOBILE_DEEP_LINK', 'budgetpro://billing'),

    // Platform-wide feature flags (per-tenant flags live in companies.features, Phase 1+).
    'features' => [
        // Inventory forecasting + auto-reorder rules: unfinished modules, rebuilt in Phase 4 (P0-12).
        'inventory_automation' => (bool) env('FEATURE_INVENTORY_AUTOMATION', false),
    ],

    // Currency used when a company has none set (companies choose their own at onboarding).
    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'UGX'),

    // Default low-stock threshold when a product has no min_stock of its own.
    'low_stock_threshold' => (float) env('SAAS_LOW_STOCK_THRESHOLD', 10),

    /*
    |--------------------------------------------------------------------------
    | API pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'per_page' => 20,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Options/typeahead endpoints
    |--------------------------------------------------------------------------
    */
    'options_limit' => 50,
    'search_limit' => 20,

    /*
    |--------------------------------------------------------------------------
    | Allowed currencies (ISO-4217). Extend as needed.
    |--------------------------------------------------------------------------
    */
    'currencies' => [
        'UGX', 'KES', 'TZS', 'RWF', 'USD', 'EUR', 'GBP', 'ZAR', 'NGN', 'GHS',
        'INR', 'CAD', 'AUD', 'JPY', 'CNY', 'AED', 'SAR', 'EGP', 'ETB', 'XAF', 'XOF',
    ],

    /*
    |--------------------------------------------------------------------------
    | File upload constraints for the API
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'max_kb' => env('SAAS_UPLOAD_MAX_KB', 5120), // 5 MB
        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
    ],
];

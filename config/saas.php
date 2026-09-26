<?php

return [
    // Where budget-pro itself is served: links that open budget-pro pages (public receipts /r/…,
    // team invites /invite/…) use it, whichever interface created them (budget-pro-new overrides app.url).
    'public_url' => env('SAAS_PUBLIC_URL', env('APP_URL', 'http://localhost')),

    // The new shop interface (budget-pro-new), linked from the classic admin's top bar. Empty = no link.
    'new_ui_url' => env('SAAS_NEW_UI_URL', ''),

    // Where team invite links (/invite/…) open. Empty = public_url (the classic invite page).
    'invite_url' => env('SAAS_INVITE_URL', ''),

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
    // Decision H2: a trial or lapsed paid plan falls back to the public Free plan instead of a lockout.
    'free_tier_fallback' => (bool) env('SAAS_FREE_TIER_FALLBACK', true),
    'free_plan' => env('SAAS_FREE_PLAN', 'free'),

    'mail_enabled' => (bool) env('SAAS_MAIL_ENABLED', false),

    // Timezone used when formatting dates for people (per-company timezones arrive in Phase 1).
    'display_timezone' => env('SAAS_DISPLAY_TIMEZONE', 'Africa/Kampala'),

    // ── Billing (POWER_PLAN §4) ──
    // Where "Upgrade" / "Renew" links and billing notices send people: the new shop interface's /plan when set
    // (e.g. https://shop.example.com/plan). Empty = the classic billing page (public_url + /billing).
    'billing_url' => env('SAAS_BILLING_URL', ''),

    // Card auto-renew with the token Flutterwave returns after a card payment. Off until tested live with a test key.
    'auto_renew' => (bool) env('SAAS_AUTO_RENEW', false),
    // How long before the period ends a saved card is charged, and the renewal reminder days.
    'auto_renew_hours_before' => (int) env('SAAS_AUTO_RENEW_HOURS_BEFORE', 48),
    'renewal_reminder_days' => [7, 3, 1],

    // Pending invoices are re-verified with Flutterwave hourly for this long, then marked abandoned.
    'reconcile_hours' => (int) env('SAAS_RECONCILE_HOURS', 72),

    // Seller details and tax printed on subscription invoices. Prices are tax-inclusive: with a rate set the
    // invoice shows the tax contained in the total. Lines left empty are simply not printed.
    'invoice' => [
        'seller_name' => env('SAAS_INVOICE_SELLER_NAME', env('APP_NAME', 'Budget Pro')),
        'seller_address' => env('SAAS_INVOICE_SELLER_ADDRESS', ''),
        'seller_tin' => env('SAAS_INVOICE_SELLER_TIN', ''),
        'seller_email' => env('SAAS_INVOICE_SELLER_EMAIL', ''),
        'seller_phone' => env('SAAS_INVOICE_SELLER_PHONE', ''),
        'tax_label' => env('SAAS_INVOICE_TAX_LABEL', 'VAT'),
        'tax_rate' => (float) env('SAAS_INVOICE_TAX_RATE', 0),
    ],

    // Deep link the payment result page offers to return to the mobile app (null hides the button).
    'mobile_deep_link' => env('MOBILE_DEEP_LINK', 'budgetpro://billing'),

    // Plan features (forecasting, multi_location, …) live on plans.features; see Entitlements.
    'features' => [],

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

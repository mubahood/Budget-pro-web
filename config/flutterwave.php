<?php

return [
    'secret_key' => env('FLW_SECRET_KEY'),
    'public_key' => env('FLW_PUBLIC_KEY'),
    'encryption_key' => env('FLW_ENCRYPTION_KEY'),

    // Shared secret used to verify incoming webhook signatures (the "verif-hash" header).
    'secret_hash' => env('FLW_SECRET_HASH'),

    'base_url' => rtrim(env('FLW_BASE_URL', 'https://api.flutterwave.com'), '/'),

    // Default (home) currency of the Flutterwave account.
    'currency' => env('FLW_CURRENCY', 'UGX'),

    // Payment options offered to Ugandan customers.
    'payment_options' => env('FLW_PAYMENT_OPTIONS', 'mobilemoneyuganda,card,banktransfer,ussd'),

    // Payment options offered to international (non-Uganda) customers — card only.
    'international_payment_options' => env('FLW_INTL_PAYMENT_OPTIONS', 'card'),

    // Currency international customers are billed in.
    'international_currency' => env('FLW_INTL_CURRENCY', 'USD'),

    'timeout' => (int) env('FLW_TIMEOUT', 20),

    // Where Flutterwave redirects the browser after a hosted-checkout payment.
    'redirect_url' => env('FLW_REDIRECT_URL', rtrim((string) env('APP_URL'), '/').'/payment/callback'),
];

<?php

/*
| The public demo shop (App\Services\Onboarding\PublicDemo).
|
| One shared account anybody can open from the website with a single click. Everything in it is
| sample data made through the app's own services, and it looks after itself:
|   - every hour it is checked and repaired (the account, the shop's settings, today's trading),
|   - every `rebuild_hours` it is built again from scratch. The new shop is filled completely
|     before anyone is moved into it, so a visitor never lands in a half-built shop.
|
| Demo shops never send messages (Messenger) and are left out of plan limits (Quotas). A visitor
| cannot change the password or email, change the currency, pay for a plan or delete the shop.
*/
return [
    'enabled' => (bool) env('DEMO_PUBLIC', true),

    // The sign-in shown on the website. The address is on our own domain, and nothing is ever sent to it.
    'email' => env('DEMO_EMAIL', 'demo@schooldynamics.ug'),
    'password' => env('DEMO_PASSWORD', 'demo2026'),

    'rebuild_hours' => (int) env('DEMO_REBUILD_HOURS', 72),

    // How long a phone's "Try the live demo" sign-in lasts (POST /api/v1/demo/session).
    'token_hours' => (int) env('DEMO_TOKEN_HOURS', 12),

    // How much trading history a fresh build has.
    'days' => (int) env('DEMO_DAYS', 60),

    // A Kampala market in Uganda shillings (Uganda is the market). Every amount in database/data/public_demo.php,
    // PublicDemoBuilder and PublicDemoWedding is in UGX, so the currency is not an env setting.
    'shop' => [
        'name' => 'Fresh Corner Market',
        'currency' => 'UGX',
        'country' => 'UG',
        'timezone' => 'Africa/Kampala',
        'address' => 'Plot 14, Ntinda Road, Kampala',
    ],
];

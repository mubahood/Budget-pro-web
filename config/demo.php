<?php

/*
| The family's public demos (App\Services\Onboarding\PublicDemo): Budget Pro's account below, and one each for Shelfie,
| Kuku Tally and Pledge & Budget under `apps`.
|
| Each is an account anybody can open from its app's website with a single click, in any of its roles. Everything in it is
| sample data made through the app's own services, and it looks after itself:
|   - every hour it is checked and repaired (the account, its settings, today's figures),
|   - every `rebuild_hours` it is built again from scratch. The new account is filled completely
|     before anyone is moved into it, so a visitor never lands in a half-built one.
|
| Demos never send messages (Messenger) and are left out of plan limits (Quotas). A visitor cannot change a password or
| email, change the currency, pay for a plan or delete the account. The sign-ins here are for the phones' API and for a
| person signing in by hand: no public page prints them.
*/
return [
    'enabled' => (bool) env('DEMO_PUBLIC', true),

    // The owner's two real invitation cards on the demo events (CardTemplates::DEMO), instead of the made-up sample. They show a
    // real couple and relatives' phone numbers, so they are off unless switched on here, and need their files in the portal.
    // Switched off, the next upkeep takes them off the demo events again.
    'real_cards' => (bool) env('EVENT_CARDS_DEMO_REAL', false),

    // Budget Pro's sign-in. The address is on our own domain, and nothing is ever sent to it.
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

    /*
    | Each app's own account, by its key in the portal's config/apps.php. `kind` says who fills it: shop (PublicDemoBuilder),
    | farm or events (AppDemoBuilder); `data` is its file in database/data; `modules` are all it ever has. `words` are what
    | its refusals call it (PublicDemo::guard); the portal's own words are in config/apps.php.
    */
    'apps' => [
        'shelfie' => [
            'username' => 'shelfie-demo',
            'email' => env('DEMO_SHELFIE_EMAIL', 'demo-shelfie@schooldynamics.ug'),
            'password' => env('DEMO_SHELFIE_PASSWORD', 'shelfie-demo-2026'),
            'kind' => 'shop',
            'data' => 'demo_shelfie',
            'modules' => ['shop', 'finance'],
            'shop' => ['name' => 'Kireka Family Mart', 'currency' => 'UGX', 'country' => 'UG', 'timezone' => 'Africa/Kampala', 'address' => 'Kira Road, Kireka'],
            'words' => ['place' => 'shop', 'name' => 'demo shop', 'own' => 'Create your own shop'],
        ],
        'kuku-tally' => [
            'username' => 'kuku-tally-demo',
            'email' => env('DEMO_KUKU_TALLY_EMAIL', 'demo-kuku-tally@schooldynamics.ug'),
            'password' => env('DEMO_KUKU_TALLY_PASSWORD', 'kuku-tally-demo-2026'),
            'kind' => 'farm',
            'data' => 'demo_kuku_tally',
            'modules' => ['poultry', 'finance'],
            'shop' => ['name' => 'Nsubuga Poultry Farm', 'currency' => 'UGX', 'country' => 'UG', 'timezone' => 'Africa/Kampala', 'address' => 'Kyetume, Mukono'],
            'words' => ['place' => 'farm', 'name' => 'demo farm', 'own' => 'Start your own farm book'],
        ],
        'pledge-and-budget' => [
            'username' => 'pledge-budget-demo',
            'email' => env('DEMO_PLEDGE_BUDGET_EMAIL', 'demo-pledge-budget@schooldynamics.ug'),
            'password' => env('DEMO_PLEDGE_BUDGET_PASSWORD', 'pledge-budget-demo-2026'),
            'kind' => 'events',
            'data' => 'demo_pledge_budget',
            'modules' => ['budget', 'finance'],
            'shop' => ['name' => 'Brian & Grace Wedding Committee', 'currency' => 'UGX', 'country' => 'UG', 'timezone' => 'Africa/Kampala', 'address' => 'Ntinda, Kampala'],
            'words' => ['place' => 'event', 'name' => 'demo event', 'own' => 'Plan your own event'],
        ],
    ],

    /*
    | The Akiba Ledger demo (App\Services\Sacco\Demo\SaccoDemo, AK-35): its own company, "Wakiso Teachers' SACCO
    | (Demo)", separate from the shop above in every way: its own sign-ins (one per role, listed in
    | database/data/demo_sacco.php), its own schedule (`sacco:demo`: healed hourly, rebuilt every rebuild_hours) and
    | its own switch. It never sends a message and never pays anything out. The password is shown on the Akiba Ledger
    | site's /demo page, so it is not a secret.
    */
    'sacco' => [
        'enabled' => (bool) env('DEMO_SACCO', true),
        'password' => env('DEMO_SACCO_PASSWORD', 'demo-sacco-2026'),
        'rebuild_hours' => (int) env('DEMO_SACCO_REBUILD_HOURS', 72),
        // How much history a fresh build has (months closed before this one).
        'months' => (int) env('DEMO_SACCO_MONTHS', 18),
        // Counter visits a month (deposits, shares, withdrawals) beside the payroll check-off.
        'counter_per_month' => (int) env('DEMO_SACCO_COUNTER', 14),
        'timezone' => 'Africa/Kampala',
    ],
];

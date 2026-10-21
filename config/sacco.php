<?php

/*
| Akiba Ledger, the `sacco` module (docs/akiba/04-architecture.md §17). Values come from the environment; no secrets
| in git. Each SACCO's own choices live in sacco_settings; these are the platform's defaults and switches.
*/
return [
    // Who regulates SACCOs (AK-14): configuration, never code. A SACCO may override it in its settings.
    'regulator' => [
        'name' => env('SACCO_REGULATOR_NAME', 'Uganda Microfinance Regulatory Authority'),
        'short' => env('SACCO_REGULATOR_SHORT', 'UMRA'),
        'portal' => env('SACCO_REGULATOR_PORTAL', ''),
    ],

    // The plan line's trial and free fallback (PlanLine, AkibaPlanSeeder; AK-15, AK-16).
    'plans' => ['trial' => env('SACCO_TRIAL_PLAN', 'akiba-trial'), 'free' => env('SACCO_FREE_PLAN', 'akiba-free')],

    // Mobile-money payouts (AK-17): off (the production default until the owner adds keys), simulated (local and
    // tests; config:doctor fails production on it) or flutterwave.
    'payouts' => ['driver' => env('SACCO_PAYOUTS_DRIVER', 'off')],

    // The secret the NIN lookup hash is keyed with (AK-31). config:doctor fails production when it is empty;
    // elsewhere the app key stands in. Changing it later breaks the duplicate lookup of every stored NIN.
    'nin_pepper' => env('SACCO_NIN_PEPPER'),

    // Loan classification and provisioning (SI 51): [class, from days, to days (null = beyond), provision bp].
    'provisioning' => [['performing', 0, 0, 100], ['watch', 1, 60, 500], ['substandard', 61, 90, 2500], ['doubtful', 91, 180, 5000], ['loss', 181, null, 10000]],

    // New SACCOs' defaults (docs/akiba/02-uganda-research.md §7.3), money in major units of the SACCO's currency.
    'defaults' => [
        'office' => ['share_par' => '10000', 'min_shares' => 5, 'entrance_fee' => '20000', 'withdrawal_approval_above' => '1000000', 'offline_receipt_max' => '5000000', 'receipts_channel' => 'sms'],
        'group' => ['share_par' => '2000', 'min_shares' => 1, 'entrance_fee' => '0', 'withdrawal_approval_above' => '0', 'offline_receipt_max' => '5000000', 'receipts_channel' => 'whatsapp'],
        'max_holding_bp' => 3333,
        'dividend_cap_bp' => 1000,
        'reserve_min_bp' => 1000,
        'education_fund_bp' => 100,
        'dormancy_months' => 24,
        'loan_multiplier_bp' => 30000,
        'loan_rate_bp_monthly' => ['staff' => 150, 'community' => 200],
        'processing_fee_bp' => 100,
        'guarantors' => 2,
        'periods_ahead' => 3,
    ],

    'country' => 'UG',
];

<?php

/*
| Estate Ledger (the rentals module): defaults, lists and the demo (docs/estate-ledger/04-architecture.md §20). No secrets.
*/
return [
    // Tenants' photos and papers (PrivateFiles): one folder for both apps. Worked out from this file, so the web
    // portal, which reads this config as it is, gets the same absolute path. Never under a public folder or a storage link.
    'private_root' => dirname(__DIR__).'/storage/app/rental-private',

    // Where tenants and landlords sign in (…/my), for the invite staff send them. Empty: the web portal's own address.
    'portal_url' => env('RENTALS_PORTAL_URL', ''),

    // The plan line's trial and free plans (EstateLedgerPlanSeeder, PlanLine::ESTATE_LEDGER).
    'plans' => ['trial' => 'estate-ledger-trial', 'free' => 'estate-ledger-free'],

    // A new account's settings (RentalsModule::settings); each can be changed in Settings.
    'defaults' => [
        'mode' => 'agency',
        'bill_days_before' => 5,
        'grace_days' => 5,
        'anchor' => 'start',
        'prorate_first' => true,
        'commission_type' => 'percent',
        'commission_bp' => 1000,
        'deposit_months' => 1,
        'deposit_held_by' => 'agency',
        'late_fee_type' => 'none',
        'late_fee_to' => 'agency',
    ],

    'unit_kinds' => [
        'single_room' => 'Single room',
        'double_room' => 'Double room',
        'self_contained' => 'Self-contained room',
        'studio' => 'Studio',
        'apartment' => 'Apartment',
        'house' => 'House',
        'shop' => 'Shop',
        'office' => 'Office',
        'stall' => 'Stall',
        'warehouse' => 'Warehouse',
        'other' => 'Other',
    ],

    'property_kinds' => ['residential' => 'Residential', 'commercial' => 'Commercial', 'mixed' => 'Mixed use', 'land' => 'Land or plots'],

    'methods' => ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank' => 'Bank', 'cheque' => 'Cheque'],

    'cycles' => [1 => 'Monthly', 3 => 'Every 3 months', 6 => 'Every 6 months', 12 => 'Yearly'],

    'item_presets' => ['Garbage', 'Security', 'Water (flat)', 'Service charge', 'Parking'],

    'expense_categories' => ['Repairs', 'Cleaning', 'Security', 'Garbage', 'Water', 'Electricity', 'Property rates', 'Rental income tax',
        'Legal', 'Insurance', 'Salaries', 'Transport', 'Brokers', 'Bank charges', 'Meals', 'Agency office', 'Other'],

    // The public demo agency (RentalsDemo, KR-21).
    'demo' => [
        'enabled' => (bool) env('DEMO_RENTALS', true),
        'password' => env('DEMO_RENTALS_PASSWORD', 'demo-estate-ledger-2026'),
        'rebuild_hours' => (int) env('DEMO_RENTALS_REBUILD_HOURS', 72),
        'months' => (int) env('DEMO_RENTALS_MONTHS', 12),
        'domain' => env('DEMO_RENTALS_DOMAIN', 'ntindaproperty.example'),
        'timezone' => 'Africa/Kampala',
    ],
];

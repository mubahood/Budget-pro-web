<?php

/*
| Setup wizard presets (plan C2 / Appendix F, P3-2). Country → currency,
| timezone, tax, mobile money and languages (decision H3); business type →
| template pack, default modules and units.
*/
return [
    'countries' => [
        'UG' => ['name' => 'Uganda', 'currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'vat' => 18, 'locales' => ['en', 'lg'],
            'momo' => ['mtn_momo' => 'MTN Mobile Money', 'airtel_money' => 'Airtel Money']],
        'KE' => ['name' => 'Kenya', 'currency' => 'KES', 'timezone' => 'Africa/Nairobi', 'vat' => 16, 'locales' => ['en', 'sw'],
            'momo' => ['mpesa' => 'M-Pesa', 'airtel_money' => 'Airtel Money']],
        'TZ' => ['name' => 'Tanzania', 'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'vat' => 18, 'locales' => ['sw', 'en'],
            'momo' => ['mpesa' => 'M-Pesa', 'tigo_pesa' => 'Tigo Pesa', 'airtel_money' => 'Airtel Money', 'halopesa' => 'HaloPesa']],
        'RW' => ['name' => 'Rwanda', 'currency' => 'RWF', 'timezone' => 'Africa/Kigali', 'vat' => 18, 'locales' => ['en'],
            'momo' => ['mtn_momo' => 'MTN MoMo', 'airtel_money' => 'Airtel Money']],
    ],

    'business_types' => [
        'retail' => ['label' => 'Retail shop / supermarket', 'modules' => ['shop', 'finance']],
        'wholesale' => ['label' => 'Wholesale', 'modules' => ['shop', 'finance']],
        'pharmacy' => ['label' => 'Pharmacy / drug shop', 'modules' => ['shop', 'finance']],
        'agro_vet' => ['label' => 'Agro-vet', 'modules' => ['shop', 'finance']],
        'hardware' => ['label' => 'Hardware', 'modules' => ['shop', 'finance']],
        'restaurant_bar' => ['label' => 'Restaurant / bar', 'modules' => ['shop', 'finance']],
        'salon' => ['label' => 'Salon / barber', 'modules' => ['shop', 'finance']],
        'boutique' => ['label' => 'Boutique / clothes', 'modules' => ['shop', 'finance']],
        'electronics' => ['label' => 'Electronics / phones', 'modules' => ['shop', 'finance']],
        'poultry' => ['label' => 'Poultry farm', 'modules' => ['poultry', 'finance']],
        'fundraising' => ['label' => 'Church / group budget & pledges', 'modules' => ['budget', 'finance']],
        'other' => ['label' => 'Other', 'modules' => ['shop', 'finance']],
    ],

    // Modules a company can switch on or off (plan C4). Hidden modules keep their data.
    'modules' => [
        'shop' => 'Shop: products, sales and stock',
        'finance' => 'Income & expenses',
        'budget' => 'Budgets & pledges',
        'poultry' => 'Poultry farm',
    ],

    // Web paths per module (first path segment, `*` = prefix) — hidden from menus and refused when the module is off.
    'module_paths' => [
        'shop' => ['stock-*', 'sale-records', 'customers', 'suppliers', 'units', 'shifts', 'goods-receipts', 'purchase-orders', 'purchase-returns', 'reorder-suggestions', 'locations', 'stock-transfers'],
        'budget' => ['budget-*', 'contribution-records', 'handover-records', 'data-exports'],
        'poultry' => ['poultry-*'],
        'finance' => ['financial-*'],
    ],

    // Pack sizes created for a new shop (name, abbreviation, pieces per pack); owners add their own.
    'default_units' => [
        'retail' => [['Half dozen', '6pk', 6], ['Dozen', 'dz', 12], ['Crate (24)', 'crt', 24]],
        'wholesale' => [['Dozen', 'dz', 12], ['Carton (12)', 'ctn', 12], ['Crate (24)', 'crt', 24], ['Bale (24)', 'bale', 24]],
        'restaurant_bar' => [['Crate (24)', 'crt', 24], ['Crate (12)', 'crt12', 12]],
        'pharmacy' => [['Box (10 strips)', 'box', 10]],
        'agro_vet' => [['Carton (12)', 'ctn', 12]],
        'hardware' => [['Box (100)', 'box', 100], ['Dozen', 'dz', 12]],
        'electronics' => [['Box (10)', 'box', 10]],
        'boutique' => [['Dozen', 'dz', 12]],
    ],

    'payment_methods' => ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'bank' => 'Bank transfer', 'credit' => 'Credit (pay later)'],

    'steps' => ['account', 'business', 'products', 'money', 'team', 'first_sale'],

    // The web /setup page shows until the getting-started checklist reaches this percentage.
    'setup_until_percent' => 60,
];

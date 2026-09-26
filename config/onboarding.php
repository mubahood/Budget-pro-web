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
        // group: how the picker groups them · icon: Font Awesome · hint: what such a shop sells (shown under the name).
        'retail' => ['label' => 'General shop / retail', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-store', 'hint' => 'Sugar, soap, soda, airtime'],
        'supermarket' => ['label' => 'Supermarket / mini-mart', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-cart-shopping', 'hint' => 'Groceries, household, baby, drinks'],
        'kiosk' => ['label' => 'Kiosk / duka / canteen', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-shop', 'hint' => 'Small sizes, sachets, airtime'],
        'wholesale' => ['label' => 'Wholesale / distributor', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-boxes-stacked', 'hint' => 'Cartons, bags, crates, bales'],
        'fresh_produce' => ['label' => 'Fruits, vegetables & produce', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-carrot', 'hint' => 'Matooke, tomatoes, beans by kg'],
        'butchery' => ['label' => 'Butchery', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-drumstick-bite', 'hint' => 'Beef, goat, pork, chicken by kg'],
        'bakery' => ['label' => 'Bakery / cake shop', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-bread-slice', 'hint' => 'Bread, cakes, pastries, snacks'],
        'restaurant_bar' => ['label' => 'Restaurant / café / bar', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-utensils', 'hint' => 'Meals, snacks, drinks by the plate'],
        'liquor_store' => ['label' => 'Liquor store / drinks depot', 'modules' => ['shop', 'finance'], 'group' => 'Food & everyday', 'icon' => 'fa-wine-bottle', 'hint' => 'Beer, spirits, wine, sodas'],
        'pharmacy' => ['label' => 'Pharmacy / drug shop', 'modules' => ['shop', 'finance'], 'group' => 'Health & beauty', 'icon' => 'fa-prescription-bottle-medical', 'hint' => 'Tablets by strip, syrups, first aid'],
        'cosmetics' => ['label' => 'Cosmetics / beauty shop', 'modules' => ['shop', 'finance'], 'group' => 'Health & beauty', 'icon' => 'fa-spray-can-sparkles', 'hint' => 'Lotions, hair products, perfumes'],
        'salon' => ['label' => 'Salon / barber', 'modules' => ['shop', 'finance'], 'group' => 'Health & beauty', 'icon' => 'fa-scissors', 'hint' => 'Services plus hair products'],
        'boutique' => ['label' => 'Boutique / clothes & shoes', 'modules' => ['shop', 'finance'], 'group' => 'Fashion & home', 'icon' => 'fa-shirt', 'hint' => 'Clothes, shoes, bags'],
        'furniture' => ['label' => 'Furniture', 'modules' => ['shop', 'finance'], 'group' => 'Fashion & home', 'icon' => 'fa-couch', 'hint' => 'Beds, mattresses, chairs, sofas'],
        'electronics' => ['label' => 'Electronics / phones', 'modules' => ['shop', 'finance'], 'group' => 'Tech, tools & parts', 'icon' => 'fa-mobile-screen', 'hint' => 'Phones, accessories, repairs'],
        'hardware' => ['label' => 'Hardware / building materials', 'modules' => ['shop', 'finance'], 'group' => 'Tech, tools & parts', 'icon' => 'fa-screwdriver-wrench', 'hint' => 'Cement, iron sheets, paint, nails'],
        'auto_spares' => ['label' => 'Spare parts (cars & boda)', 'modules' => ['shop', 'finance'], 'group' => 'Tech, tools & parts', 'icon' => 'fa-car-battery', 'hint' => 'Oils, filters, brake pads, tyres'],
        'stationery' => ['label' => 'Stationery / bookshop / printing', 'modules' => ['shop', 'finance'], 'group' => 'Tech, tools & parts', 'icon' => 'fa-pen-ruler', 'hint' => 'Books, pens, photocopy, printing'],
        'agro_vet' => ['label' => 'Agro-vet / farm inputs', 'modules' => ['shop', 'finance'], 'group' => 'Farming', 'icon' => 'fa-seedling', 'hint' => 'Seeds, fertiliser, animal drugs'],
        'poultry' => ['label' => 'Poultry farm', 'modules' => ['poultry', 'finance'], 'group' => 'Farming', 'icon' => 'fa-egg', 'hint' => 'Feeds, birds, eggs, vaccines'],
        'fundraising' => ['label' => 'Church / group budget & pledges', 'modules' => ['budget', 'finance'], 'group' => 'Something else', 'icon' => 'fa-hands-praying', 'hint' => 'Budgets, pledges, contributions'],
        'other' => ['label' => 'Something else', 'modules' => ['shop', 'finance'], 'group' => 'Something else', 'icon' => 'fa-shapes', 'hint' => 'Services and a few common items'],
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
        // Ping Pin (phone tracking) is a separate product: shown only to companies that use it (see AdminMenu::modulesOn).
        'pingpin' => ['tracked-devices*', 'tracking-map*', 'device-*'],
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
        'supermarket' => [['Half dozen', '6pk', 6], ['Dozen', 'dz', 12], ['Carton (12)', 'ctn', 12], ['Crate (24)', 'crt', 24]],
        'kiosk' => [['Dozen', 'dz', 12], ['Crate (24)', 'crt', 24]],
        'liquor_store' => [['Crate (24)', 'crt', 24], ['Crate (12)', 'crt12', 12], ['Carton (12)', 'ctn', 12]],
        'stationery' => [['Dozen', 'dz', 12], ['Ream (500)', 'ream', 500], ['Box (50)', 'box50', 50]],
        'cosmetics' => [['Dozen', 'dz', 12], ['Carton (12)', 'ctn', 12]],
        'auto_spares' => [['Box (10)', 'box', 10], ['Carton (12)', 'ctn', 12]],
        'fresh_produce' => [['Bunch', 'bunch', 1], ['Sack (100kg)', 'sack', 100]],
    ],

    'payment_methods' => ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'bank' => 'Bank transfer', 'credit' => 'Credit (pay later)'],

    'steps' => ['account', 'business', 'products', 'money', 'team', 'first_sale'],

    // The web /setup page shows until the getting-started checklist reaches this percentage.
    'setup_until_percent' => 60,
];

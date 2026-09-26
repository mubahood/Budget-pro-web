<?php

/*
| Setup wizard presets (plan C2 / Appendix F, P3-2). Country → currency,
| timezone, tax, mobile money and languages (decision H3); business type →
| template pack, default modules and units.
*/
return [
    'countries' => [
        // East Africa first (mobile money built in), then the rest of the world alphabetically.
        // region 'ea' also sees the East-African items of the template packs.
        'UG' => ['name' => 'Uganda', 'currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'vat' => 18, 'locales' => ['en', 'lg'], 'region' => 'ea',
            'momo' => ['mtn_momo' => 'MTN Mobile Money', 'airtel_money' => 'Airtel Money']],
        'KE' => ['name' => 'Kenya', 'currency' => 'KES', 'timezone' => 'Africa/Nairobi', 'vat' => 16, 'locales' => ['en', 'sw'], 'region' => 'ea',
            'momo' => ['mpesa' => 'M-Pesa', 'airtel_money' => 'Airtel Money']],
        'TZ' => ['name' => 'Tanzania', 'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'vat' => 18, 'locales' => ['sw', 'en'], 'region' => 'ea',
            'momo' => ['mpesa' => 'M-Pesa', 'tigo_pesa' => 'Tigo Pesa', 'airtel_money' => 'Airtel Money', 'halopesa' => 'HaloPesa']],
        'RW' => ['name' => 'Rwanda', 'currency' => 'RWF', 'timezone' => 'Africa/Kigali', 'vat' => 18, 'locales' => ['en'], 'region' => 'ea',
            'momo' => ['mtn_momo' => 'MTN MoMo', 'airtel_money' => 'Airtel Money']],
        'AU' => ['name' => 'Australia', 'currency' => 'AUD', 'timezone' => 'Australia/Sydney', 'vat' => 10, 'locales' => ['en']],
        'BI' => ['name' => 'Burundi', 'currency' => 'BIF', 'timezone' => 'Africa/Bujumbura', 'vat' => 18, 'locales' => ['fr', 'en'], 'region' => 'ea'],
        'CM' => ['name' => 'Cameroon', 'currency' => 'XAF', 'timezone' => 'Africa/Douala', 'vat' => 19.25, 'locales' => ['fr', 'en'], 'momo' => ['mtn_momo' => 'MTN MoMo', 'orange_money' => 'Orange Money']],
        'CA' => ['name' => 'Canada', 'currency' => 'CAD', 'timezone' => 'America/Toronto', 'vat' => 5, 'locales' => ['en', 'fr']],
        'CD' => ['name' => 'DR Congo', 'currency' => 'CDF', 'timezone' => 'Africa/Lubumbashi', 'vat' => 16, 'locales' => ['fr'], 'region' => 'ea'],
        'CI' => ['name' => "Côte d'Ivoire", 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'vat' => 18, 'locales' => ['fr'], 'momo' => ['orange_money' => 'Orange Money', 'mtn_momo' => 'MTN MoMo']],
        'EG' => ['name' => 'Egypt', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'vat' => 14, 'locales' => ['en']],
        'ET' => ['name' => 'Ethiopia', 'currency' => 'ETB', 'timezone' => 'Africa/Addis_Ababa', 'vat' => 15, 'locales' => ['en'], 'momo' => ['telebirr' => 'telebirr']],
        'FR' => ['name' => 'France', 'currency' => 'EUR', 'timezone' => 'Europe/Paris', 'vat' => 20, 'locales' => ['fr']],
        'DE' => ['name' => 'Germany', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'vat' => 19, 'locales' => ['en']],
        'GH' => ['name' => 'Ghana', 'currency' => 'GHS', 'timezone' => 'Africa/Accra', 'vat' => 15, 'locales' => ['en'], 'momo' => ['mtn_momo' => 'MTN MoMo', 'telecel_cash' => 'Telecel Cash', 'airteltigo_money' => 'AirtelTigo Money']],
        'IN' => ['name' => 'India', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'vat' => 18, 'locales' => ['en']],
        'IE' => ['name' => 'Ireland', 'currency' => 'EUR', 'timezone' => 'Europe/Dublin', 'vat' => 23, 'locales' => ['en']],
        'MW' => ['name' => 'Malawi', 'currency' => 'MWK', 'timezone' => 'Africa/Blantyre', 'vat' => 16.5, 'locales' => ['en'], 'momo' => ['airtel_money' => 'Airtel Money', 'tnm_mpamba' => 'TNM Mpamba']],
        'NL' => ['name' => 'Netherlands', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam', 'vat' => 21, 'locales' => ['en']],
        'NG' => ['name' => 'Nigeria', 'currency' => 'NGN', 'timezone' => 'Africa/Lagos', 'vat' => 7.5, 'locales' => ['en']],
        'SA' => ['name' => 'Saudi Arabia', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'vat' => 15, 'locales' => ['en']],
        'SN' => ['name' => 'Senegal', 'currency' => 'XOF', 'timezone' => 'Africa/Dakar', 'vat' => 18, 'locales' => ['fr'], 'momo' => ['orange_money' => 'Orange Money', 'wave' => 'Wave']],
        'ZA' => ['name' => 'South Africa', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg', 'vat' => 15, 'locales' => ['en']],
        'SS' => ['name' => 'South Sudan', 'currency' => 'SSP', 'timezone' => 'Africa/Juba', 'vat' => 18, 'locales' => ['en'], 'region' => 'ea'],
        'AE' => ['name' => 'United Arab Emirates', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'vat' => 5, 'locales' => ['en']],
        'GB' => ['name' => 'United Kingdom', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'vat' => 20, 'locales' => ['en']],
        'US' => ['name' => 'United States', 'currency' => 'USD', 'timezone' => 'America/New_York', 'vat' => null, 'locales' => ['en']],
        'ZM' => ['name' => 'Zambia', 'currency' => 'ZMW', 'timezone' => 'Africa/Lusaka', 'vat' => 16, 'locales' => ['en'], 'momo' => ['mtn_momo' => 'MTN MoMo', 'airtel_money' => 'Airtel Money']],
        'ZW' => ['name' => 'Zimbabwe', 'currency' => 'USD', 'timezone' => 'Africa/Harare', 'vat' => 15, 'locales' => ['en'], 'momo' => ['ecocash' => 'EcoCash']],
        'XX' => ['name' => 'Another country', 'currency' => 'USD', 'timezone' => 'UTC', 'vat' => null, 'locales' => ['en']],
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

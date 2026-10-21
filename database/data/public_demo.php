<?php

/*
| The public demo shop's catalogue and people (App\Services\Onboarding\PublicDemoBuilder).
| A neighbourhood market in Kampala, in Uganda shillings (whole shillings, rounded the way shops price).
| Photos are in database/data/demo-photos (CC0, see LICENSE.md).
|
| Product row: [name, category, sub-category, unit, sell, cost, stock, reorder at, photo, supplier, extras]
|   extras: plu (weighed, sold by the kg), age (minimum age), life (days: sold in dated batches),
|           tax ('standard' | 'zero'), none of them required.
| A stock of 0 is deliberate (sold out, on order); some sit below their reorder level on purpose.
*/
return [
    // [name, phone, email, payment terms days, lead time days] (fictional numbers)
    'suppliers' => [
        'farm' => ['Green Valley Farms, Mukono', '+256 772 555 110', 'orders@greenvalley.example', 7, 2],
        'dairy' => ['Northbrook Dairy, Mbarara', '+256 772 555 111', 'sales@northbrook.example', 14, 2],
        'bakery' => ['Kisementi Bakehouse', '+256 772 555 112', 'hello@kisementibakehouse.example', 7, 1],
        'drinks' => ['Lake Victoria Beverage Distributors', '+256 772 555 113', 'orders@lvbev.example', 30, 4],
        'pantry' => ['Kikuubo Wholesale Foods', '+256 772 555 114', 'accounts@kikuubofoods.example', 30, 5],
        'home' => ['Brightline Home & Electronics', '+256 772 555 115', 'trade@brightline.example', 30, 7],
    ],

    'products' => [
        // Fresh produce: weighed at the till (PLU codes are the real international ones).
        ['Bananas', 'Fresh Produce', 'Fruit', 'kg', 3000, 1500, 48, 15, 'bananas', 'farm', ['plu' => '4011', 'tax' => 'zero', 'pop' => 5]],
        ['Red Apples', 'Fresh Produce', 'Fruit', 'kg', 12000, 7000, 36, 12, 'apples', 'farm', ['plu' => '4131', 'tax' => 'zero']],
        ['Navel Oranges', 'Fresh Produce', 'Fruit', 'kg', 5000, 2500, 30, 10, 'oranges', 'farm', ['plu' => '3107', 'tax' => 'zero']],
        ['Lemons', 'Fresh Produce', 'Fruit', 'kg', 6000, 3000, 9, 10, 'lemon', 'farm', ['plu' => '4053', 'tax' => 'zero']],
        ['Watermelon', 'Fresh Produce', 'Fruit', 'pcs', 8000, 4000, 14, 6, 'watermelon', 'farm', ['tax' => 'zero']],
        ['Hass Avocados', 'Fresh Produce', 'Fruit', 'pcs', 1500, 700, 60, 20, 'avocado', 'farm', ['tax' => 'zero', 'pop' => 4]],
        ['Vine Tomatoes', 'Fresh Produce', 'Vegetables', 'kg', 5000, 2500, 25, 10, 'tomatoes', 'farm', ['plu' => '4664', 'tax' => 'zero']],
        ['Carrots', 'Fresh Produce', 'Vegetables', 'kg', 4000, 2000, 32, 10, 'carrots', 'farm', ['plu' => '4562', 'tax' => 'zero']],
        ['Yellow Onions', 'Fresh Produce', 'Vegetables', 'kg', 4500, 2200, 40, 12, 'onions', 'farm', ['plu' => '4093', 'tax' => 'zero']],
        ['Irish Potatoes', 'Fresh Produce', 'Vegetables', 'kg', 3000, 1500, 55, 15, 'potatoes', 'farm', ['plu' => '4072', 'tax' => 'zero']],
        ['Fresh Flowers Bunch', 'Fresh Produce', 'Flowers', 'pcs', 35000, 18000, 10, 4, 'flowers', 'farm', ['tax' => 'standard']],

        // Bakery
        ['Country White Loaf', 'Bakery', 'Bread', 'pcs', 6000, 3500, 24, 8, 'bread', 'bakery', ['tax' => 'zero', 'pop' => 4]],
        ['Butter Croissant', 'Bakery', 'Pastries', 'pcs', 4000, 2000, 7, 12, 'croissant', 'bakery', ['tax' => 'zero']],
        ['Blueberry Muffin', 'Bakery', 'Pastries', 'pcs', 5000, 2500, 18, 8, 'muffin', 'bakery', ['tax' => 'zero']],
        ['Chocolate Chip Cookies 12pk', 'Bakery', 'Biscuits', 'pcs', 12000, 6000, 30, 10, 'cookies', 'bakery', ['tax' => 'zero']],

        // Dairy & eggs: sold in dated batches (first to expire, first out)
        ['Whole Milk 2L', 'Dairy & Eggs', 'Milk', 'pcs', 8000, 5500, 30, 12, 'milk', 'dairy', ['life' => 10, 'tax' => 'zero', 'pop' => 4]],
        ['Free-Range Eggs 12pk', 'Dairy & Eggs', 'Eggs', 'pcs', 7000, 4800, 24, 10, 'eggs', 'dairy', ['life' => 28, 'tax' => 'zero', 'pop' => 4]],
        ['Aged Cheddar 250g', 'Dairy & Eggs', 'Cheese', 'pcs', 25000, 15000, 14, 6, 'cheese', 'dairy', ['life' => 60, 'tax' => 'zero']],
        ['Greek Yogurt 1kg', 'Dairy & Eggs', 'Yogurt', 'pcs', 22000, 13000, 12, 8, 'yogurt', 'dairy', ['life' => 18, 'tax' => 'zero']],
        ['Salted Butter 500g', 'Dairy & Eggs', 'Butter', 'pcs', 25000, 16000, 10, 6, null, 'dairy', ['life' => 90, 'tax' => 'zero']],

        // Drinks
        ['Whole Bean Coffee 500g', 'Beverages', 'Coffee & Tea', 'pcs', 35000, 18000, 22, 8, 'coffee', 'drinks', ['tax' => 'zero']],
        ['Iced Tea 2L', 'Beverages', 'Coffee & Tea', 'pcs', 9000, 4500, 20, 8, 'tea', 'drinks', ['tax' => 'zero']],
        ['Orange Juice 1.5L', 'Beverages', 'Juice', 'pcs', 12000, 7000, 18, 8, 'juice', 'drinks', ['life' => 21, 'tax' => 'zero']],
        ['Spring Water 1.5L', 'Beverages', 'Water', 'pcs', 2000, 800, 96, 24, 'water', 'drinks', ['tax' => 'zero', 'pop' => 4]],
        ['Sparkling Lemonade 330ml', 'Beverages', 'Soft Drinks', 'pcs', 2500, 1000, 72, 24, null, 'drinks', ['tax' => 'standard']],
        ['Nile Special Lager 6 × 500ml', 'Beverages', 'Beer & Wine', 'pcs', 30000, 18000, 26, 8, 'beer', 'drinks', ['age' => 18, 'tax' => 'standard']],
        ['Cabernet Sauvignon 750ml', 'Beverages', 'Beer & Wine', 'pcs', 55000, 32000, 18, 6, 'wine', 'drinks', ['age' => 18, 'tax' => 'standard']],

        // Pantry
        ['Spaghetti 500g', 'Pantry', 'Pasta & Rice', 'pcs', 5000, 2500, 60, 20, 'pasta', 'pantry', ['tax' => 'zero']],
        ['Jasmine Rice 5kg', 'Pantry', 'Pasta & Rice', 'pcs', 30000, 18000, 28, 10, null, 'pantry', ['tax' => 'zero']],
        ['Extra Virgin Olive Oil 500ml', 'Pantry', 'Oils & Condiments', 'pcs', 38000, 22000, 20, 6, 'oil', 'pantry', ['tax' => 'zero']],
        ['Wildflower Honey 450g', 'Pantry', 'Spreads', 'pcs', 25000, 13000, 3, 6, 'honey', 'pantry', ['tax' => 'zero']],
        ['Cane Sugar 2kg', 'Pantry', 'Baking', 'pcs', 11000, 8500, 30, 10, 'sugar', 'pantry', ['tax' => 'zero']],
        ['All-Purpose Flour 2kg', 'Pantry', 'Baking', 'pcs', 9000, 6000, 26, 10, 'flour', 'pantry', ['tax' => 'zero']],
        ['Honey Oat Cereal 500g', 'Pantry', 'Breakfast', 'pcs', 18000, 10000, 34, 10, 'cereal', 'pantry', ['tax' => 'zero']],
        ['Dark Chocolate Bar 70%', 'Pantry', 'Snacks', 'pcs', 12000, 5000, 48, 15, 'chocolate', 'pantry', ['tax' => 'standard']],
        ['Roasted Hazelnuts 250g', 'Pantry', 'Snacks', 'pcs', 25000, 13000, 22, 8, 'nuts', 'pantry', ['tax' => 'zero']],

        // Household & personal care
        ['Toilet Paper 12 rolls', 'Household', 'Paper Goods', 'pcs', 18000, 11000, 30, 10, 'tissue', 'home', ['tax' => 'standard']],
        ['Laundry Detergent 3L', 'Household', 'Cleaning', 'pcs', 35000, 21000, 16, 6, null, 'home', ['tax' => 'standard']],
        ['Handmade Oat Soap', 'Household', 'Personal Care', 'pcs', 8000, 3000, 40, 12, 'soap', 'home', ['tax' => 'standard']],
        ['Bamboo Toothbrush 2pk', 'Household', 'Personal Care', 'pcs', 10000, 4000, 25, 10, 'toothpaste', 'home', ['tax' => 'standard']],
        ['Soy Pillar Candle', 'Household', 'Home', 'pcs', 20000, 9000, 15, 5, 'candle', 'home', ['tax' => 'standard']],
        ['Enamel Camp Mug', 'Household', 'Home', 'pcs', 25000, 11000, 12, 4, 'mug', 'home', ['tax' => 'standard']],
        ['LED Bulb 60W 4pk', 'Household', 'Electrical', 'pcs', 30000, 16000, 20, 6, 'bulb', 'home', ['tax' => 'standard']],
        ['AA Batteries 8pk', 'Household', 'Electrical', 'pcs', 20000, 10000, 4, 10, 'batteries', 'home', ['tax' => 'standard']],
        ['Wireless Headphones', 'Household', 'Electronics', 'pcs', 150000, 85000, 0, 3, 'headphones', 'home', ['tax' => 'standard']],
    ],

    // The account visitors sign in as, and the team around it (the manager builds the shop).
    // The builder knows each by their first name (daniel, maria, kevin, samuel), so keep those when renaming.
    'owner' => 'Alex Kato',
    'team' => [
        ['Daniel Ssebunya', 'manager'],
        ['Maria Nalubega', 'cashier'],
        ['Kevin Okello', 'cashier'],
        ['Samuel Mugisha', 'stock_keeper'],
        ['Hannah Achieng', 'accountant'],
    ],

    // [name, phone, email, credit limit, terms days, extras] (fictional numbers)
    'customers' => [
        ['Ntinda Café', '+256 772 555 140', 'orders@ntindacafe.example', 3000000, 14, ['account' => true, 'regular' => 6]],
        ['Aisha Nakimuli', '+256 772 555 141', 'aisha.nakimuli@example.com', 0, 0, ['regular' => 5, 'marketing' => true]],
        ['Joel Kizza', '+256 772 555 142', 'joel.kizza@example.com', 0, 0, ['regular' => 4, 'gift_card' => 100000]],
        ['Sarah Namaganda', '+256 772 555 143', null, 600000, 30, ['regular' => 3, 'credit' => true]],
        ['James Opio', '+256 772 555 144', 'jopio@example.com', 1000000, 30, ['regular' => 3, 'credit' => true, 'overdue' => true]],
        ['Patience Akello', '+256 772 555 145', 'patience.akello@example.com', 0, 0, ['regular' => 3, 'marketing' => true]],
        ['Lucas Ssali', '+256 772 555 146', null, 0, 0, ['regular' => 2, 'opt_out' => true]],
        ['Esther Nansubuga', '+256 772 555 147', 'esther.n@example.com', 0, 0, ['regular' => 2]],
        ['Omar Lubega', '+256 772 555 148', null, 400000, 14, ['regular' => 2, 'credit' => true]],
        ['Christine Auma', '+256 772 555 149', 'christine.auma@example.com', 0, 0, ['regular' => 1]],
        ['Noah Mugenyi', '+256 772 555 150', null, 0, 0, ['regular' => 1]],
        ['Grace Atuhaire', '+256 772 555 151', 'grace.atuhaire@example.com', 0, 0, ['regular' => 1]],
    ],

    // Monthly running costs [category, description, amount (UGX), day of month]
    'expenses' => [
        ['Rent', 'Shop rent', 3500000, 1],
        ['Utilities', 'Electricity (Yaka)', 450000, 8],
        ['Utilities', 'Water (NWSC)', 120000, 8],
        ['Internet & Phone', 'Fibre internet and phone line', 150000, 12],
        ['Transport', 'Delivery van fuel', 400000, 15],
        ['Cleaning', 'Cleaning supplies and floor service', 200000, 20],
        ['Insurance', 'Shop contents insurance', 250000, 25],
    ],
];

<?php

/*
| The public demo shop's catalogue and people (App\Services\Onboarding\PublicDemoBuilder).
| A neighbourhood market in US dollars. Photos are in database/data/demo-photos (CC0, see LICENSE.md).
|
| Product row: [name, category, sub-category, unit, sell, cost, stock, reorder at, photo, supplier, extras]
|   extras: plu (weighed, sold by the kg), age (minimum age), life (days: sold in dated batches),
|           tax ('standard' | 'zero'), none of them required.
| A stock of 0 is deliberate (sold out, on order); some sit below their reorder level on purpose.
*/
return [
    'suppliers' => [
        'farm' => ['Green Valley Farms', '(207) 555-0110', 'orders@greenvalley.example', 7, 2],
        'dairy' => ['Northbrook Dairy Co.', '(207) 555-0111', 'sales@northbrook.example', 14, 2],
        'bakery' => ['Harbor Bakehouse', '(207) 555-0112', 'hello@harborbakehouse.example', 7, 1],
        'drinks' => ['Coastal Beverage Distributors', '(207) 555-0113', 'orders@coastalbev.example', 30, 4],
        'pantry' => ['Atlantic Wholesale Foods', '(207) 555-0114', 'accounts@atlanticfoods.example', 30, 5],
        'home' => ['Brightline Home & Electronics', '(207) 555-0115', 'trade@brightline.example', 30, 7],
    ],

    'products' => [
        // Fresh produce: weighed at the till (PLU codes are the real international ones).
        ['Bananas', 'Fresh Produce', 'Fruit', 'kg', 1.29, 0.62, 48, 15, 'bananas', 'farm', ['plu' => '4011', 'tax' => 'zero', 'pop' => 5]],
        ['Red Apples', 'Fresh Produce', 'Fruit', 'kg', 3.49, 1.80, 36, 12, 'apples', 'farm', ['plu' => '4131', 'tax' => 'zero']],
        ['Navel Oranges', 'Fresh Produce', 'Fruit', 'kg', 2.99, 1.45, 30, 10, 'oranges', 'farm', ['plu' => '3107', 'tax' => 'zero']],
        ['Lemons', 'Fresh Produce', 'Fruit', 'kg', 3.99, 1.90, 9, 10, 'lemon', 'farm', ['plu' => '4053', 'tax' => 'zero']],
        ['Watermelon', 'Fresh Produce', 'Fruit', 'pcs', 5.99, 3.10, 14, 6, 'watermelon', 'farm', ['tax' => 'zero']],
        ['Hass Avocados', 'Fresh Produce', 'Fruit', 'pcs', 1.49, 0.70, 60, 20, 'avocado', 'farm', ['tax' => 'zero', 'pop' => 4]],
        ['Vine Tomatoes', 'Fresh Produce', 'Vegetables', 'kg', 4.49, 2.20, 25, 10, 'tomatoes', 'farm', ['plu' => '4664', 'tax' => 'zero']],
        ['Carrots', 'Fresh Produce', 'Vegetables', 'kg', 1.99, 0.85, 32, 10, 'carrots', 'farm', ['plu' => '4562', 'tax' => 'zero']],
        ['Yellow Onions', 'Fresh Produce', 'Vegetables', 'kg', 1.79, 0.75, 40, 12, 'onions', 'farm', ['plu' => '4093', 'tax' => 'zero']],
        ['Russet Potatoes', 'Fresh Produce', 'Vegetables', 'kg', 1.59, 0.68, 55, 15, 'potatoes', 'farm', ['plu' => '4072', 'tax' => 'zero']],
        ['Fresh Flowers Bunch', 'Fresh Produce', 'Flowers', 'pcs', 12.99, 6.50, 10, 4, 'flowers', 'farm', ['tax' => 'standard']],

        // Bakery
        ['Country White Loaf', 'Bakery', 'Bread', 'pcs', 3.99, 1.90, 24, 8, 'bread', 'bakery', ['tax' => 'zero', 'pop' => 4]],
        ['Butter Croissant', 'Bakery', 'Pastries', 'pcs', 2.25, 0.95, 7, 12, 'croissant', 'bakery', ['tax' => 'zero']],
        ['Blueberry Muffin', 'Bakery', 'Pastries', 'pcs', 2.75, 1.10, 18, 8, 'muffin', 'bakery', ['tax' => 'zero']],
        ['Chocolate Chip Cookies 12pk', 'Bakery', 'Biscuits', 'pcs', 4.49, 2.10, 30, 10, 'cookies', 'bakery', ['tax' => 'zero']],

        // Dairy & eggs: sold in dated batches (first to expire, first out)
        ['Whole Milk 2L', 'Dairy & Eggs', 'Milk', 'pcs', 3.29, 2.15, 30, 12, 'milk', 'dairy', ['life' => 10, 'tax' => 'zero', 'pop' => 4]],
        ['Free-Range Eggs 12pk', 'Dairy & Eggs', 'Eggs', 'pcs', 5.49, 3.60, 24, 10, 'eggs', 'dairy', ['life' => 28, 'tax' => 'zero', 'pop' => 4]],
        ['Aged Cheddar 250g', 'Dairy & Eggs', 'Cheese', 'pcs', 5.99, 3.40, 14, 6, 'cheese', 'dairy', ['life' => 60, 'tax' => 'zero']],
        ['Greek Yogurt 1kg', 'Dairy & Eggs', 'Yogurt', 'pcs', 6.49, 3.90, 12, 8, 'yogurt', 'dairy', ['life' => 18, 'tax' => 'zero']],
        ['Salted Butter 500g', 'Dairy & Eggs', 'Butter', 'pcs', 5.29, 3.30, 10, 6, null, 'dairy', ['life' => 90, 'tax' => 'zero']],

        // Drinks
        ['Whole Bean Coffee 500g', 'Beverages', 'Coffee & Tea', 'pcs', 12.99, 6.80, 22, 8, 'coffee', 'drinks', ['tax' => 'zero']],
        ['Iced Tea 2L', 'Beverages', 'Coffee & Tea', 'pcs', 3.49, 1.60, 20, 8, 'tea', 'drinks', ['tax' => 'zero']],
        ['Orange Juice 1.5L', 'Beverages', 'Juice', 'pcs', 4.99, 2.70, 18, 8, 'juice', 'drinks', ['life' => 21, 'tax' => 'zero']],
        ['Spring Water 1.5L', 'Beverages', 'Water', 'pcs', 1.29, 0.45, 96, 24, 'water', 'drinks', ['tax' => 'zero', 'pop' => 4]],
        ['Sparkling Lemonade 330ml', 'Beverages', 'Soft Drinks', 'pcs', 1.49, 0.55, 72, 24, null, 'drinks', ['tax' => 'standard']],
        ['Harbor Lager 6 × 330ml', 'Beverages', 'Beer & Wine', 'pcs', 10.99, 6.40, 26, 8, 'beer', 'drinks', ['age' => 21, 'tax' => 'standard']],
        ['Cabernet Sauvignon 750ml', 'Beverages', 'Beer & Wine', 'pcs', 14.99, 8.20, 18, 6, 'wine', 'drinks', ['age' => 21, 'tax' => 'standard']],

        // Pantry
        ['Spaghetti 500g', 'Pantry', 'Pasta & Rice', 'pcs', 1.99, 0.85, 60, 20, 'pasta', 'pantry', ['tax' => 'zero']],
        ['Jasmine Rice 5kg', 'Pantry', 'Pasta & Rice', 'pcs', 8.49, 4.90, 28, 10, null, 'pantry', ['tax' => 'zero']],
        ['Extra Virgin Olive Oil 500ml', 'Pantry', 'Oils & Condiments', 'pcs', 9.99, 5.60, 20, 6, 'oil', 'pantry', ['tax' => 'zero']],
        ['Wildflower Honey 450g', 'Pantry', 'Spreads', 'pcs', 8.99, 4.80, 3, 6, 'honey', 'pantry', ['tax' => 'zero']],
        ['Cane Sugar 2kg', 'Pantry', 'Baking', 'pcs', 3.79, 2.10, 30, 10, 'sugar', 'pantry', ['tax' => 'zero']],
        ['All-Purpose Flour 2kg', 'Pantry', 'Baking', 'pcs', 4.29, 2.30, 26, 10, 'flour', 'pantry', ['tax' => 'zero']],
        ['Honey Oat Cereal 500g', 'Pantry', 'Breakfast', 'pcs', 4.99, 2.60, 34, 10, 'cereal', 'pantry', ['tax' => 'zero']],
        ['Dark Chocolate Bar 70%', 'Pantry', 'Snacks', 'pcs', 3.49, 1.40, 48, 15, 'chocolate', 'pantry', ['tax' => 'standard']],
        ['Roasted Hazelnuts 250g', 'Pantry', 'Snacks', 'pcs', 6.99, 3.60, 22, 8, 'nuts', 'pantry', ['tax' => 'zero']],

        // Household & personal care
        ['Toilet Paper 12 rolls', 'Household', 'Paper Goods', 'pcs', 11.99, 7.20, 30, 10, 'tissue', 'home', ['tax' => 'standard']],
        ['Laundry Detergent 3L', 'Household', 'Cleaning', 'pcs', 13.49, 8.10, 16, 6, null, 'home', ['tax' => 'standard']],
        ['Handmade Oat Soap', 'Household', 'Personal Care', 'pcs', 4.99, 1.90, 40, 12, 'soap', 'home', ['tax' => 'standard']],
        ['Bamboo Toothbrush 2pk', 'Household', 'Personal Care', 'pcs', 5.49, 2.20, 25, 10, 'toothpaste', 'home', ['tax' => 'standard']],
        ['Soy Pillar Candle', 'Household', 'Home', 'pcs', 8.99, 3.80, 15, 5, 'candle', 'home', ['tax' => 'standard']],
        ['Enamel Camp Mug', 'Household', 'Home', 'pcs', 11.99, 5.10, 12, 4, 'mug', 'home', ['tax' => 'standard']],
        ['LED Bulb 60W 4pk', 'Household', 'Electrical', 'pcs', 9.99, 5.20, 20, 6, 'bulb', 'home', ['tax' => 'standard']],
        ['AA Batteries 8pk', 'Household', 'Electrical', 'pcs', 8.49, 4.30, 4, 10, 'batteries', 'home', ['tax' => 'standard']],
        ['Wireless Headphones', 'Household', 'Electronics', 'pcs', 49.99, 28.00, 0, 3, 'headphones', 'home', ['tax' => 'standard']],
    ],

    // The account visitors sign in as, and the team around it (the manager builds the shop).
    'owner' => 'Alex Morgan',
    'team' => [
        ['Daniel Brooks', 'manager'],
        ['Maria Lopez', 'cashier'],
        ['Kevin Chen', 'cashier'],
        ['Samuel Osei', 'stock_keeper'],
        ['Hannah Weiss', 'accountant'],
    ],

    // [name, phone, email, credit limit, terms days, extras]
    'customers' => [
        ['Harbor Café', '(207) 555-0140', 'orders@harborcafe.example', 800, 14, ['account' => true, 'regular' => 6]],
        ['Amara Okafor', '(207) 555-0141', 'amara.okafor@example.com', 0, 0, ['regular' => 5, 'marketing' => true]],
        ['Kenji Sato', '(207) 555-0142', 'kenji.sato@example.com', 0, 0, ['regular' => 4, 'gift_card' => 50]],
        ['Sofia Rossi', '(207) 555-0143', null, 150, 30, ['regular' => 3, 'credit' => true]],
        ['James Carter', '(207) 555-0144', 'jcarter@example.com', 250, 30, ['regular' => 3, 'credit' => true, 'overdue' => true]],
        ['Priya Nair', '(207) 555-0145', 'priya.nair@example.com', 0, 0, ['regular' => 3, 'marketing' => true]],
        ['Lucas Silva', '(207) 555-0146', null, 0, 0, ['regular' => 2, 'opt_out' => true]],
        ['Emma Thompson', '(207) 555-0147', 'emma.t@example.com', 0, 0, ['regular' => 2]],
        ['Omar Haddad', '(207) 555-0148', null, 100, 14, ['regular' => 2, 'credit' => true]],
        ['Chloe Martin', '(207) 555-0149', 'chloe.martin@example.com', 0, 0, ['regular' => 1]],
        ['Noah Williams', '(207) 555-0150', null, 0, 0, ['regular' => 1]],
        ['Grace Mensah', '(207) 555-0151', 'grace.mensah@example.com', 0, 0, ['regular' => 1]],
    ],

    // Monthly running costs [category, description, amount, day of month]
    'expenses' => [
        ['Rent', 'Shop rent', 1450, 1],
        ['Utilities', 'Electricity', 318.40, 8],
        ['Utilities', 'Water and sewer', 64.20, 8],
        ['Internet & Phone', 'Fibre internet and phone line', 79.99, 12],
        ['Transport', 'Delivery van fuel', 142.75, 15],
        ['Cleaning', 'Cleaning supplies and floor service', 96.00, 20],
        ['Insurance', 'Shop contents insurance', 118.00, 25],
    ],
];

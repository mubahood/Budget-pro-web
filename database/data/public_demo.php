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
    // The shop's own details (its name, currency and address are in config/demo.php).
    'company' => [
        'business_type' => 'supermarket',
        'phone' => '+256 772 555 100',
        'email' => 'hello@freshcorner.example',
        'domain' => 'freshcorner.example',
        'sku' => 'FCM',
        'slogan' => 'Fresh every morning',
        'receipt_header' => "Fresh Corner Market\nPlot 14, Ntinda Road, Kampala · 0772 555 100",
        'receipt_footer' => 'Thank you for shopping local. Returns within 14 days with this receipt.',
    ],

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

    // The account visitors open, and the team around it (the first one, the manager, builds the shop).
    // `seats` says who does what in the builder's shop day, by first name in lower case.
    'owner' => 'Alex Kato',
    'seats' => ['manager' => 'daniel', 'morning' => 'maria', 'evening' => 'kevin', 'keeper' => 'samuel'],
    'team' => [
        ['Daniel Ssebunya', 'manager'],
        ['Maria Nalubega', 'cashier'],
        ['Kevin Okello', 'cashier'],
        ['Samuel Mugisha', 'stock_keeper'],
        ['Hannah Achieng', 'accountant'],
    ],

    // One button per role on the /demo page (PublicDemo::roles): what each is called and what they do there.
    'roles' => [
        'owner' => ['label' => 'Owner', 'what' => 'Sees everything: sales, profit, the farm, the events and the team.'],
        'manager' => ['label' => 'Manager', 'what' => 'Runs the shop day to day, approves refunds and orders stock.'],
        'cashier' => ['label' => 'Cashier', 'what' => 'Sells at the till and takes refunds. No buying prices or profit.'],
        'stock_keeper' => ['label' => 'Stock keeper', 'what' => 'Receives deliveries and counts the shelves.'],
        'accountant' => ['label' => 'Accountant', 'what' => 'Reads the reports and keeps the money records.'],
    ],

    // [name, phone, email, credit limit, terms days, extras] (fictional numbers)
    'customers' => [
        ['Ntinda Café', '+256 772 555 140', 'orders@ntindacafe.example', 3000000, 14, ['account' => true, 'regular' => 6, 'notes' => 'Café on the corner. Orders bread and dairy twice a week, pays fortnightly by bank transfer.']],
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
    'weekly_wages' => 600000,

    // Deals, set up on the first morning. A target is ['product', name] or ['category', name].
    'deals' => [
        ['name' => 'Croissants: 3 for UGX 10,000', 'type' => 'mix_match', 'qty' => 3, 'price' => 10000, 'targets' => [['product', 'Butter Croissant']]],
        ['name' => 'Weekend dairy 10% off', 'type' => 'percent_off', 'percent' => 10, 'days' => [6, 7], 'targets' => [['category', 'Dairy & Eggs']]],
        ['name' => 'Lemonade: buy 2, get 1 free', 'type' => 'buy_x_get_y', 'buy' => 2, 'get' => 1, 'targets' => [['product', 'Sparkling Lemonade 330ml']]],
        ['name' => 'Bread & butter for UGX 28,000', 'type' => 'bundle', 'price' => 28000, 'targets' => [['product', 'Country White Loaf'], ['product', 'Salted Butter 500g']]],
        ['name' => 'Members: coffee beans UGX 30,000', 'type' => 'fixed_price', 'price' => 30000, 'member_only' => true, 'targets' => [['product', 'Whole Bean Coffee 500g']]],
        ['name' => 'Bakery happy hour 20% off', 'type' => 'percent_off', 'percent' => 20, 'from' => '18:00', 'to' => '21:00', 'targets' => [['category', 'Bakery']]],
        ['name' => 'Welcome coupon: UGX 5,000 off', 'type' => 'coupon', 'code' => 'WELCOME5', 'min_spend' => 50000, 'amount' => 5000],
    ],

    // What the account customer (extras `account`) takes first thing on Mondays and Thursdays: name => quantity.
    'standing_order' => ['Country White Loaf' => 6, 'Butter Croissant' => 12, 'Whole Milk 2L' => 4, 'Free-Range Eggs 12pk' => 2, 'Navel Oranges' => 3.2],

    // Near its date, found this morning and marked down by 30%.
    'short_dated' => ['item' => 'Greek Yogurt 1kg', 'qty' => 5, 'note' => 'Found at the back of the chiller'],

    // Days before today => [product, type, quantity, reason].
    'write_offs' => [
        12 => ['Free-Range Eggs 12pk', 'Damage', 1, 'Tray dropped while shelving'],
        6 => ['Whole Milk 2L', 'Expired', 2, 'Past use-by date'],
        4 => ['Bananas', 'Expired', 1.4, 'Overripe'],
        20 => ['Iced Tea 2L', 'Internal Use', 1, 'Staff room'],
    ],

    // Last month's pantry count: name => how many short.
    'count_differences' => ['Spaghetti 500g' => 2, 'Cane Sugar 2kg' => 1],

    // The first gift card buys these two.
    'gift_card_basket' => ['Whole Bean Coffee 500g', 'Roasted Hazelnuts 250g'],

    // One order sent yesterday, one still being written: product => quantity.
    'orders' => [
        'sent' => ['supplier' => 'home', 'note' => 'Headphones sold out; batteries low.', 'lines' => ['Wireless Headphones' => 6, 'AA Batteries 8pk' => 24]],
        'draft' => ['supplier' => 'drinks', 'note' => 'Weekend drinks', 'lines' => ['Spring Water 1.5L' => 48, 'Sparkling Lemonade 330ml' => 24, 'Orange Juice 1.5L' => 24]],
    ],

    'price_rise' => ['item' => 'Whole Bean Coffee 500g', 'price' => 38000],

    // The team chat: [seat, days from today, hour, minute, message].
    'chat' => [
        ['manager', -1, 18, 40, 'Dairy order is in for Wednesday. Northbrook confirmed a 6:45 drop.'],
        ['keeper', -1, 19, 5, 'Headphones are sold out. I put them on the Brightline order with the batteries.'],
        ['morning', 0, 7, 2, 'Till 1 open with the UGX 100,000 float. The café order is packed for pickup.'],
        ['keeper', 0, 7, 10, 'Five yogurts found at the back of the chiller, date is in two days. Marked down 30%.'],
        ['manager', 0, 7, 25, 'Thanks both. Coffee goes up to UGX 38,000 on Monday, labels are in the print queue.'],
    ],
];

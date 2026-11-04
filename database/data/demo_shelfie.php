<?php

/*
| Shelfie's own demo shop (App\Services\Onboarding\PublicDemoBuilder): a family mini-market in Kireka, near Kampala, in Uganda
| shillings. The same shape as database/data/public_demo.php, with its own catalogue, team and customers. Every person
| and number is made up. Photos are in database/data/demo-photos (CC0, see LICENSE.md).
|
| The supplier keys and the category names carry the builder's rhythm of a shop (who delivers when, what is counted), so
| keep those; everything else is this shop's own.
*/
return [
    'company' => [
        'business_type' => 'supermarket',
        'phone' => '+256 772 555 300',
        'email' => 'hello@kirekafamilymart.example',
        'domain' => 'kirekafamilymart.example',
        'sku' => 'KFM',
        'slogan' => 'Everything for the home, close by',
        'receipt_header' => "Kireka Family Mart\nKira Road, Kireka · 0772 555 300",
        'receipt_footer' => 'Webale nnyo. Returns within 7 days with this receipt.',
    ],

    // [name, phone, email, payment terms days, lead time days] (fictional numbers)
    'suppliers' => [
        'farm' => ['Kayunga Growers Cooperative', '+256 772 555 310', 'orders@kayungagrowers.example', 7, 2],
        'dairy' => ['Kiruhura Dairy Farmers', '+256 772 555 311', 'sales@kiruhuradairy.example', 14, 2],
        'bakery' => ['Banda Hot Bakery', '+256 772 555 312', 'hello@bandahotbakery.example', 7, 1],
        'drinks' => ['Nakawa Drinks Depot', '+256 772 555 313', 'orders@nakawadrinks.example', 30, 4],
        'pantry' => ['Kikuubo Traders Wholesale', '+256 772 555 314', 'accounts@kikuubotraders.example', 30, 5],
        'home' => ['Nasser Road Household Supplies', '+256 772 555 315', 'trade@nasserhousehold.example', 30, 7],
    ],

    // [name, category, sub-category, unit, sell, cost, stock, reorder at, photo, supplier, extras]
    'products' => [
        ['Sweet Bananas', 'Fresh Produce', 'Fruit', 'kg', 2500, 1300, 40, 12, 'bananas', 'farm', ['plu' => '4011', 'tax' => 'zero', 'pop' => 5]],
        ['Avocado', 'Fresh Produce', 'Fruit', 'pcs', 1000, 500, 60, 20, 'avocado', 'farm', ['tax' => 'zero', 'pop' => 4]],
        ['Watermelon', 'Fresh Produce', 'Fruit', 'pcs', 7000, 3500, 12, 5, 'watermelon', 'farm', ['tax' => 'zero']],
        ['Oranges', 'Fresh Produce', 'Fruit', 'kg', 4000, 2000, 28, 10, 'oranges', 'farm', ['plu' => '3107', 'tax' => 'zero']],
        ['Tomatoes', 'Fresh Produce', 'Vegetables', 'kg', 4000, 2000, 30, 10, 'tomatoes', 'farm', ['plu' => '4664', 'tax' => 'zero', 'pop' => 4]],
        ['Red Onions', 'Fresh Produce', 'Vegetables', 'kg', 4500, 2300, 35, 12, 'onions', 'farm', ['plu' => '4093', 'tax' => 'zero', 'pop' => 3]],
        ['Irish Potatoes', 'Fresh Produce', 'Vegetables', 'kg', 3000, 1600, 50, 15, 'potatoes', 'farm', ['plu' => '4072', 'tax' => 'zero', 'pop' => 3]],
        ['Carrots', 'Fresh Produce', 'Vegetables', 'kg', 3500, 1800, 25, 10, 'carrots', 'farm', ['plu' => '4562', 'tax' => 'zero']],

        ['Hot Loaf Bread 500g', 'Bakery', 'Bread', 'pcs', 4500, 3600, 24, 8, 'bread', 'bakery', ['tax' => 'zero', 'pop' => 5]],
        ['Queen Cakes 6pk', 'Bakery', 'Cakes', 'pcs', 5000, 3000, 8, 10, 'muffin', 'bakery', ['tax' => 'zero', 'pop' => 3]],
        ['Mandazi 6pk', 'Bakery', 'Cakes', 'pcs', 3000, 1800, 20, 8, 'croissant', 'bakery', ['tax' => 'zero', 'pop' => 3]],
        ['Glucose Biscuits 12pk', 'Bakery', 'Biscuits', 'pcs', 6000, 4200, 30, 10, 'cookies', 'bakery', ['tax' => 'zero']],

        ['Fresh Milk 500ml', 'Dairy & Eggs', 'Milk', 'pcs', 2000, 1500, 40, 15, 'milk', 'dairy', ['life' => 7, 'tax' => 'zero', 'pop' => 5]],
        ['Eggs, tray of 30', 'Dairy & Eggs', 'Eggs', 'pcs', 13000, 11000, 20, 8, 'eggs', 'dairy', ['life' => 28, 'tax' => 'zero', 'pop' => 4]],
        ['Plain Yoghurt 500ml', 'Dairy & Eggs', 'Yoghurt', 'pcs', 6000, 4200, 14, 6, 'yogurt', 'dairy', ['life' => 18, 'tax' => 'zero', 'pop' => 2]],
        ['Blue Band 250g', 'Dairy & Eggs', 'Butter', 'pcs', 4500, 3700, 18, 6, null, 'dairy', ['life' => 90, 'tax' => 'zero', 'pop' => 3]],

        ['Rwenzori Water 1.5L', 'Beverages', 'Water', 'pcs', 2000, 1200, 96, 24, 'water', 'drinks', ['tax' => 'zero', 'pop' => 5]],
        ['Coca-Cola 500ml', 'Beverages', 'Soft Drinks', 'pcs', 2000, 1500, 72, 24, null, 'drinks', ['tax' => 'standard', 'pop' => 5]],
        ['Stoney Tangawizi 500ml', 'Beverages', 'Soft Drinks', 'pcs', 2000, 1500, 60, 24, null, 'drinks', ['tax' => 'standard', 'pop' => 3]],
        ['Mango Juice 1L', 'Beverages', 'Juice', 'pcs', 6500, 4500, 20, 8, 'juice', 'drinks', ['life' => 21, 'tax' => 'zero', 'pop' => 2]],
        ['Ugandan Black Tea 100g', 'Beverages', 'Coffee & Tea', 'pcs', 3500, 2300, 24, 8, 'tea', 'drinks', ['tax' => 'zero']],
        ['Ground Coffee 250g', 'Beverages', 'Coffee & Tea', 'pcs', 12000, 7500, 18, 6, 'coffee', 'drinks', ['tax' => 'zero']],
        ['Nile Special 500ml', 'Beverages', 'Beer', 'pcs', 5000, 3800, 48, 12, 'beer', 'drinks', ['age' => 18, 'tax' => 'standard', 'pop' => 3]],

        ['Kakira Sugar 1kg', 'Pantry', 'Sugar & Flour', 'pcs', 5500, 4800, 40, 12, 'sugar', 'pantry', ['tax' => 'zero', 'pop' => 5]],
        ['Maize Flour 2kg', 'Pantry', 'Sugar & Flour', 'pcs', 7000, 5600, 30, 10, 'flour', 'pantry', ['tax' => 'zero', 'pop' => 4]],
        ['Super Rice 1kg', 'Pantry', 'Rice & Beans', 'pcs', 5000, 4100, 36, 12, null, 'pantry', ['tax' => 'zero', 'pop' => 4]],
        ['Nambaale Beans 1kg', 'Pantry', 'Rice & Beans', 'pcs', 5500, 4300, 30, 10, null, 'pantry', ['tax' => 'zero', 'pop' => 3]],
        ['Mukwano Cooking Oil 1L', 'Pantry', 'Oils', 'pcs', 9000, 7600, 24, 8, 'oil', 'pantry', ['tax' => 'zero', 'pop' => 3]],
        ['Spaghetti 500g', 'Pantry', 'Pasta', 'pcs', 4500, 3100, 30, 10, 'pasta', 'pantry', ['tax' => 'zero']],
        ['Groundnut Paste 250g', 'Pantry', 'Spreads', 'pcs', 6000, 4000, 18, 6, 'nuts', 'pantry', ['tax' => 'zero']],
        ['Local Honey 250g', 'Pantry', 'Spreads', 'pcs', 12000, 8000, 3, 6, 'honey', 'pantry', ['tax' => 'zero']],
        ['Corn Flakes 500g', 'Pantry', 'Breakfast', 'pcs', 15000, 10500, 14, 5, 'cereal', 'pantry', ['tax' => 'zero']],

        ['Geisha Bathing Soap', 'Household', 'Soap', 'pcs', 2500, 1900, 60, 20, 'soap', 'home', ['tax' => 'standard', 'pop' => 4]],
        ['Omo Washing Powder 500g', 'Household', 'Soap', 'pcs', 6500, 5300, 30, 10, null, 'home', ['tax' => 'standard', 'pop' => 3]],
        ['Toilet Paper 4 rolls', 'Household', 'Paper Goods', 'pcs', 6000, 4300, 30, 10, 'tissue', 'home', ['tax' => 'standard']],
        ['Colgate Toothpaste 100ml', 'Household', 'Personal Care', 'pcs', 5500, 4100, 20, 8, 'toothpaste', 'home', ['tax' => 'standard']],
        ['Candles 6pk', 'Household', 'Home', 'pcs', 3000, 1800, 25, 8, 'candle', 'home', ['tax' => 'standard']],
        ['LED Bulb 9W', 'Household', 'Electrical', 'pcs', 8000, 5000, 15, 5, 'bulb', 'home', ['tax' => 'standard']],
        ['AA Batteries 4pk', 'Household', 'Electrical', 'pcs', 6000, 3800, 4, 10, 'batteries', 'home', ['tax' => 'standard']],
        ['Rechargeable Torch', 'Household', 'Electrical', 'pcs', 25000, 16000, 0, 3, null, 'home', ['tax' => 'standard']],
    ],

    // The account visitors open, and the team around it (the first one, the manager, builds the shop).
    // `seats` says who does what in the builder's shop day, by first name in lower case.
    'owner' => 'Ruth Nabirye',
    'seats' => ['manager' => 'ivan', 'morning' => 'sharon', 'evening' => 'peter', 'keeper' => 'moses'],
    'team' => [
        ['Ivan Mugerwa', 'manager'],
        ['Sharon Ainembabazi', 'cashier'],
        ['Peter Ocen', 'cashier'],
        ['Moses Kintu', 'stock_keeper'],
        ['Lydia Nambi', 'accountant'],
    ],

    // One button per role on the /demo page (PublicDemo::roles).
    'roles' => [
        'owner' => ['label' => 'Shop owner', 'what' => 'Sees everything: sales, profit, stock, debts and the team.'],
        'manager' => ['label' => 'Manager', 'what' => 'Runs the shop day to day, approves refunds and orders stock.'],
        'cashier' => ['label' => 'Cashier', 'what' => 'Sells at the till and takes refunds. No buying prices or profit.'],
        'stock_keeper' => ['label' => 'Stock keeper', 'what' => 'Receives deliveries and counts the shelves.'],
        'accountant' => ['label' => 'Accountant', 'what' => 'Reads the reports and keeps the money records.'],
    ],

    // [name, phone, email, credit limit, terms days, extras] (fictional numbers)
    'customers' => [
        ['St. Kizito Staff Canteen', '+256 772 555 340', 'canteen@stkizitokireka.example', 2000000, 14, ['account' => true, 'regular' => 6, 'notes' => 'School staff canteen up the road. Bread, milk and eggs twice a week, pays fortnightly by bank transfer.']],
        ['Mama Tendo', '+256 772 555 341', null, 300000, 30, ['regular' => 5, 'credit' => true]],
        ['Godfrey Sserunjogi', '+256 772 555 342', 'g.sserunjogi@example.com', 0, 0, ['regular' => 4, 'gift_card' => 100000]],
        ['Agnes Namukasa', '+256 772 555 343', null, 500000, 30, ['regular' => 3, 'credit' => true, 'overdue' => true]],
        ['Boda Stage Kireka', '+256 772 555 344', null, 200000, 14, ['regular' => 3, 'credit' => true]],
        ['Florence Adongo', '+256 772 555 345', 'f.adongo@example.com', 0, 0, ['regular' => 3, 'marketing' => true]],
        ['Ronald Tumwine', '+256 772 555 346', null, 0, 0, ['regular' => 2, 'opt_out' => true]],
        ['Betty Nanyonga', '+256 772 555 347', 'betty.n@example.com', 0, 0, ['regular' => 2, 'marketing' => true]],
        ['Hassan Waiswa', '+256 772 555 348', null, 0, 0, ['regular' => 1]],
        ['Juliet Kemigisa', '+256 772 555 349', null, 0, 0, ['regular' => 1]],
    ],

    // Monthly running costs [category, description, amount (UGX), day of month]
    'expenses' => [
        ['Rent', 'Shop rent', 1800000, 1],
        ['Utilities', 'Electricity (Yaka)', 280000, 8],
        ['Utilities', 'Water (NWSC)', 60000, 8],
        ['Internet & Phone', 'Mobile data and airtime', 80000, 12],
        ['Transport', 'Boda deliveries and Kikuubo trips', 250000, 15],
        ['Cleaning', 'Cleaning supplies', 70000, 20],
    ],
    'weekly_wages' => 350000,

    // Deals, set up on the first morning. A target is ['product', name] or ['category', name].
    'deals' => [
        ['name' => 'Mandazi: 3 packs for UGX 8,000', 'type' => 'mix_match', 'qty' => 3, 'price' => 8000, 'targets' => [['product', 'Mandazi 6pk']]],
        ['name' => 'Weekend milk and eggs 5% off', 'type' => 'percent_off', 'percent' => 5, 'days' => [6, 7], 'targets' => [['category', 'Dairy & Eggs']]],
        ['name' => 'Soda: buy 5, get 1 free', 'type' => 'buy_x_get_y', 'buy' => 5, 'get' => 1, 'targets' => [['product', 'Coca-Cola 500ml']]],
        ['name' => 'Bread & Blue Band for UGX 8,000', 'type' => 'bundle', 'price' => 8000, 'targets' => [['product', 'Hot Loaf Bread 500g'], ['product', 'Blue Band 250g']]],
        ['name' => 'Welcome coupon: UGX 2,000 off', 'type' => 'coupon', 'code' => 'KARIBU2', 'min_spend' => 30000, 'amount' => 2000],
    ],

    // What the account customer (extras `account`) takes first thing on Mondays and Thursdays: name => quantity.
    'standing_order' => ['Hot Loaf Bread 500g' => 6, 'Fresh Milk 500ml' => 12, 'Eggs, tray of 30' => 1, 'Tomatoes' => 2.5],

    // Near its date, found this morning and marked down by 30%.
    'short_dated' => ['item' => 'Plain Yoghurt 500ml', 'qty' => 4, 'note' => 'Found behind the crates in the fridge'],

    // Days before today => [product, type, quantity, reason].
    'write_offs' => [
        11 => ['Eggs, tray of 30', 'Damage', 1, 'Tray dropped on the way in'],
        5 => ['Fresh Milk 500ml', 'Expired', 3, 'Past use-by date'],
        3 => ['Tomatoes', 'Expired', 1.2, 'Gone soft'],
        19 => ['Rwenzori Water 1.5L', 'Internal Use', 2, 'Staff drinking water'],
    ],

    // Last month's pantry count: name => how many short.
    'count_differences' => ['Spaghetti 500g' => 2, 'Kakira Sugar 1kg' => 1],

    // The first gift card buys these two.
    'gift_card_basket' => ['Ground Coffee 250g', 'Corn Flakes 500g'],

    // One order sent yesterday, one still being written: product => quantity.
    'orders' => [
        'sent' => ['supplier' => 'home', 'note' => 'Torches sold out; batteries low.', 'lines' => ['Rechargeable Torch' => 6, 'AA Batteries 4pk' => 24]],
        'draft' => ['supplier' => 'drinks', 'note' => 'Weekend sodas and water', 'lines' => ['Rwenzori Water 1.5L' => 48, 'Coca-Cola 500ml' => 48, 'Stoney Tangawizi 500ml' => 24]],
    ],

    'price_rise' => ['item' => 'Kakira Sugar 1kg', 'price' => 6000],

    // The team chat: [seat, days from today, hour, minute, message].
    'chat' => [
        ['manager', -1, 18, 30, 'Dairy order is in for Wednesday. Kiruhura will drop it before seven.'],
        ['keeper', -1, 19, 0, 'Torches are finished. I put them on the Nasser Road order with the batteries.'],
        ['morning', 0, 7, 3, 'Till open with the UGX 100,000 float. The canteen order is packed.'],
        ['keeper', 0, 7, 12, 'Four yoghurts found behind the crates, date is in two days. Marked down 30%.'],
        ['manager', 0, 7, 28, 'Thanks. Sugar goes up to UGX 6,000 on Monday, Kikuubo has raised the price.'],
    ],
];

<?php

/*
| Template packs (plan C3, POWER_PLAN §3.1): common products per business type, with typical
| Kampala shelf prices in UGX. Versioned: bump `version` when a pack changes.
| Rows: [name, category, sub-category, unit, selling price UGX, buying price UGX, optional per-currency overrides]
|
| Prices for Kenya, Tanzania and Rwanda are converted from UGX with the fixed `rates` below
| (approximate mid-market rates, 2026: 1 USD ≈ 3,650 UGX ≈ 129 KES ≈ 2,550 TZS ≈ 1,420 RWF)
| and rounded to prices a shop would write on a shelf (ProductTemplateSeeder::nice):
|   below 10 → whole units · below 100 → 5 · below 1,000 → 10 · below 10,000 → 50 ·
|   below 100,000 → 500 · above → 1,000.
| They are a starting point: the owner edits every price before the products are created. A row
| may override a converted price with a 7th element, e.g. ['KES' => ['sell' => 50, 'cost' => 40]].
*/
return [
    'version' => 2,
    // Multiply a UGX price by this to get the local price.
    'rates' => ['UGX' => 1, 'KES' => 1 / 28.3, 'TZS' => 0.70, 'RWF' => 0.39],
    'packs' => [
        'retail' => [
            ['Sugar 1kg', 'Groceries', 'Sugar & salt', 'pcs', 5000, 4400], ['Salt 500g', 'Groceries', 'Sugar & salt', 'pcs', 1000, 800],
            ['Rice (Super) 1kg', 'Groceries', 'Rice & flour', 'kg', 5000, 4300], ['Maize flour 1kg', 'Groceries', 'Rice & flour', 'kg', 3000, 2500],
            ['Wheat flour 2kg', 'Groceries', 'Rice & flour', 'pcs', 9000, 8000], ['Cooking oil 1L', 'Groceries', 'Cooking oil', 'pcs', 8500, 7600],
            ['Cooking oil 5L', 'Groceries', 'Cooking oil', 'pcs', 38000, 35000], ['Beans (Nambale) 1kg', 'Groceries', 'Beans & grains', 'kg', 5000, 4200],
            ['Tea leaves 250g', 'Groceries', 'Tea & coffee', 'pcs', 4000, 3300], ['Instant coffee 50g', 'Groceries', 'Tea & coffee', 'pcs', 6000, 5000],
            ['Milk 500ml', 'Groceries', 'Dairy', 'pcs', 1500, 1200], ['Bread 500g', 'Groceries', 'Bakery', 'pcs', 4500, 3900],
            ['Eggs (tray of 30)', 'Groceries', 'Eggs', 'tray', 13000, 11500], ['Blue band 250g', 'Groceries', 'Dairy', 'pcs', 4000, 3400],
            ['Soda 500ml', 'Drinks', 'Soft drinks', 'pcs', 1500, 1150], ['Soda 300ml (crate of 24)', 'Drinks', 'Soft drinks', 'crate', 20000, 17500],
            ['Water 500ml', 'Drinks', 'Water', 'pcs', 1000, 700], ['Water 1.5L', 'Drinks', 'Water', 'pcs', 2000, 1500],
            ['Juice 1L', 'Drinks', 'Juice', 'pcs', 5000, 4200], ['Energy drink 500ml', 'Drinks', 'Soft drinks', 'pcs', 3000, 2400],
            ['Bar soap 800g', 'Household', 'Soap & detergent', 'pcs', 5000, 4300], ['Washing powder 500g', 'Household', 'Soap & detergent', 'pcs', 4000, 3400],
            ['Bathing soap', 'Personal care', 'Soap', 'pcs', 2500, 2000], ['Toothpaste 100ml', 'Personal care', 'Oral care', 'pcs', 4000, 3300],
            ['Toothbrush', 'Personal care', 'Oral care', 'pcs', 2000, 1400], ['Vaseline 100ml', 'Personal care', 'Skin care', 'pcs', 4000, 3300],
            ['Sanitary pads', 'Personal care', 'Hygiene', 'pcs', 4000, 3300], ['Toilet paper (roll)', 'Household', 'Paper', 'pcs', 1000, 750],
            ['Matchbox', 'Household', 'Kitchen', 'pcs', 200, 140], ['Candles (pack)', 'Household', 'Lighting', 'pcs', 3000, 2400],
            ['Paraffin 1L', 'Household', 'Fuel', 'L', 5500, 4900], ['Batteries AA (pair)', 'Household', 'Lighting', 'pcs', 2000, 1500],
            ['Biscuits (small)', 'Snacks', 'Biscuits', 'pcs', 500, 380], ['Biscuits (family pack)', 'Snacks', 'Biscuits', 'pcs', 5000, 4200],
            ['Sweets (piece)', 'Snacks', 'Sweets', 'pcs', 100, 70], ['Chapati', 'Snacks', 'Ready food', 'pcs', 1000, 600],
            ['Airtime 1,000', 'Airtime', 'Airtime', 'pcs', 1000, 950], ['Airtime 5,000', 'Airtime', 'Airtime', 'pcs', 5000, 4750],
            ['Exercise book 96 pages', 'Stationery', 'Books', 'pcs', 1000, 750], ['Pen (blue)', 'Stationery', 'Pens', 'pcs', 500, 300],
        ],
        'wholesale' => [
            ['Sugar 50kg bag', 'Groceries', 'Sugar & salt', 'bag', 210000, 200000], ['Rice 25kg bag', 'Groceries', 'Rice & flour', 'bag', 115000, 105000],
            ['Maize flour 25kg bag', 'Groceries', 'Rice & flour', 'bag', 70000, 63000], ['Cooking oil 20L jerrycan', 'Groceries', 'Cooking oil', 'jerrycan', 150000, 140000],
            ['Salt 25 x 500g bale', 'Groceries', 'Sugar & salt', 'bale', 22000, 19000], ['Soda crate 300ml x 24', 'Drinks', 'Soft drinks', 'crate', 17500, 16000],
            ['Water 500ml x 24', 'Drinks', 'Water', 'carton', 13000, 11000], ['Bar soap carton (x 25)', 'Household', 'Soap & detergent', 'carton', 100000, 92000],
            ['Washing powder carton', 'Household', 'Soap & detergent', 'carton', 90000, 82000], ['Biscuits carton', 'Snacks', 'Biscuits', 'carton', 42000, 38000],
            ['Toilet paper bale (x 40)', 'Household', 'Paper', 'bale', 32000, 28000], ['Tea leaves carton', 'Groceries', 'Tea & coffee', 'carton', 90000, 82000],
            ['Beans 100kg bag', 'Groceries', 'Beans & grains', 'bag', 420000, 395000], ['Wheat flour 50kg bag', 'Groceries', 'Rice & flour', 'bag', 200000, 188000],
            ['Matchbox bundle (x 100)', 'Household', 'Kitchen', 'bundle', 15000, 13000],
        ],
        'pharmacy' => [
            ['Paracetamol 500mg (strip of 10)', 'Medicines', 'Pain relief', 'strip', 1000, 600], ['Ibuprofen 400mg (strip)', 'Medicines', 'Pain relief', 'strip', 2000, 1300],
            ['Amoxicillin 500mg (strip)', 'Medicines', 'Antibiotics', 'strip', 3000, 2000], ['Coartem (adult dose)', 'Medicines', 'Antimalarials', 'pack', 12000, 9000],
            ['ORS sachet', 'Medicines', 'Rehydration', 'sachet', 1000, 600], ['Zinc tablets (strip)', 'Medicines', 'Rehydration', 'strip', 2000, 1300],
            ['Cough syrup 100ml', 'Medicines', 'Cough & cold', 'bottle', 6000, 4200], ['Multivitamin (tin of 30)', 'Supplements', 'Vitamins', 'tin', 10000, 7000],
            ['Antacid tablets (strip)', 'Medicines', 'Stomach', 'strip', 2000, 1300], ['Metronidazole 200mg (strip)', 'Medicines', 'Antibiotics', 'strip', 1500, 900],
            ['Bandage (crepe)', 'First aid', 'Dressings', 'pcs', 3000, 2000], ['Plaster (roll)', 'First aid', 'Dressings', 'pcs', 3000, 2000],
            ['Surgical gloves (pair)', 'First aid', 'Protective', 'pair', 1000, 600], ['Face masks (pack of 10)', 'First aid', 'Protective', 'pack', 5000, 3500],
            ['Condoms (pack of 3)', 'Family planning', 'Condoms', 'pack', 2000, 1200], ['Pregnancy test', 'Family planning', 'Tests', 'pcs', 3000, 1800],
            ['Malaria RDT', 'Tests', 'Rapid tests', 'pcs', 5000, 3000], ['Syringe 5ml', 'First aid', 'Consumables', 'pcs', 500, 250],
        ],
        'agro_vet' => [
            ['Maize seed 2kg', 'Seeds', 'Maize', 'pcs', 16000, 13000], ['Bean seed 1kg', 'Seeds', 'Beans', 'kg', 8000, 6500],
            ['Tomato seed 50g', 'Seeds', 'Vegetables', 'pcs', 25000, 20000], ['DAP fertiliser 50kg', 'Fertilisers', 'Planting', 'bag', 180000, 165000],
            ['Urea 50kg', 'Fertilisers', 'Top dressing', 'bag', 150000, 138000], ['NPK 1kg', 'Fertilisers', 'Planting', 'kg', 4000, 3300],
            ['Herbicide 1L', 'Crop protection', 'Herbicides', 'L', 25000, 20000], ['Insecticide 250ml', 'Crop protection', 'Insecticides', 'pcs', 12000, 9500],
            ['Fungicide 500g', 'Crop protection', 'Fungicides', 'pcs', 15000, 12000], ['Layers mash 70kg', 'Animal feeds', 'Poultry feeds', 'bag', 130000, 118000],
            ['Broiler starter 50kg', 'Animal feeds', 'Poultry feeds', 'bag', 125000, 113000], ['Dairy meal 70kg', 'Animal feeds', 'Cattle feeds', 'bag', 95000, 85000],
            ['Dewormer (bolus)', 'Veterinary', 'Dewormers', 'pcs', 3000, 2000], ['Acaricide 100ml', 'Veterinary', 'Tick control', 'pcs', 10000, 8000],
            ['Newcastle vaccine (100 doses)', 'Veterinary', 'Vaccines', 'vial', 8000, 6000], ['Knapsack sprayer 16L', 'Tools', 'Sprayers', 'pcs', 90000, 75000],
            ['Hoe', 'Tools', 'Hand tools', 'pcs', 15000, 12000], ['Panga', 'Tools', 'Hand tools', 'pcs', 12000, 9500],
        ],
        'hardware' => [
            ['Cement 50kg', 'Building', 'Cement', 'bag', 36000, 33500], ['Iron sheet 28 gauge', 'Roofing', 'Iron sheets', 'pcs', 38000, 34000],
            ['Roofing nails 1kg', 'Fasteners', 'Nails', 'kg', 9000, 7500], ['Wire nails 4 inch 1kg', 'Fasteners', 'Nails', 'kg', 7000, 5800],
            ['Y12 steel bar', 'Building', 'Steel', 'pcs', 38000, 34500], ['Binding wire 1kg', 'Building', 'Steel', 'kg', 8000, 6500],
            ['Paint 4L (white)', 'Paint', 'Emulsion', 'tin', 45000, 38000], ['Paint brush 3 inch', 'Paint', 'Tools', 'pcs', 5000, 3500],
            ['PVC pipe 1/2 inch', 'Plumbing', 'Pipes', 'pcs', 12000, 9500], ['Tap (brass)', 'Plumbing', 'Fittings', 'pcs', 15000, 11000],
            ['Padlock (medium)', 'Security', 'Locks', 'pcs', 15000, 11000], ['Hinges (pair)', 'Fasteners', 'Hinges', 'pair', 6000, 4200],
            ['Electrical cable 1.5mm (m)', 'Electrical', 'Cables', 'm', 2500, 1900], ['Socket (double)', 'Electrical', 'Fittings', 'pcs', 12000, 9000],
            ['Energy saver bulb', 'Electrical', 'Lighting', 'pcs', 7000, 5000], ['Wheelbarrow', 'Tools', 'Site tools', 'pcs', 180000, 155000],
            ['Spade', 'Tools', 'Site tools', 'pcs', 20000, 16000], ['Timber 2x2 (piece)', 'Building', 'Timber', 'pcs', 6000, 4800],
        ],
        'restaurant_bar' => [
            ['Rolex', 'Food', 'Snacks', 'pcs', 3000, 1600], ['Chips & chicken', 'Food', 'Meals', 'plate', 15000, 9000],
            ['Local meal (matooke, beans)', 'Food', 'Meals', 'plate', 7000, 3800], ['Pilao', 'Food', 'Meals', 'plate', 10000, 6000],
            ['Chapati', 'Food', 'Snacks', 'pcs', 1000, 500], ['Samosa', 'Food', 'Snacks', 'pcs', 1000, 500],
            ['Tea (cup)', 'Drinks', 'Hot drinks', 'cup', 2000, 700], ['Coffee (cup)', 'Drinks', 'Hot drinks', 'cup', 3000, 1100],
            ['Soda 300ml', 'Drinks', 'Soft drinks', 'bottle', 1500, 800], ['Water 500ml', 'Drinks', 'Water', 'bottle', 1500, 700],
            ['Beer 500ml', 'Drinks', 'Beer', 'bottle', 5000, 3500], ['Spirit 250ml', 'Drinks', 'Spirits', 'bottle', 10000, 7500],
            ['Fresh juice (glass)', 'Drinks', 'Juice', 'glass', 4000, 1800], ['Goat muchomo (stick)', 'Food', 'Grill', 'pcs', 5000, 2800],
        ],
        'salon' => [
            ['Haircut (men)', 'Services', 'Hair', 'service', 5000, 0], ['Hair wash & set', 'Services', 'Hair', 'service', 15000, 0],
            ['Braiding (full head)', 'Services', 'Hair', 'service', 60000, 0], ['Manicure', 'Services', 'Nails', 'service', 15000, 0],
            ['Pedicure', 'Services', 'Nails', 'service', 20000, 0], ['Shave', 'Services', 'Hair', 'service', 3000, 0],
            ['Hair relaxer', 'Products', 'Hair care', 'pcs', 15000, 11000], ['Hair extensions (pack)', 'Products', 'Hair', 'pack', 12000, 8500],
            ['Shampoo 400ml', 'Products', 'Hair care', 'pcs', 12000, 9000], ['Nail polish', 'Products', 'Nails', 'pcs', 5000, 3200],
            ['Hair food 250g', 'Products', 'Hair care', 'pcs', 6000, 4300],
        ],
        'boutique' => [
            ['T-shirt', 'Clothes', 'Tops', 'pcs', 20000, 12000], ['Shirt (formal)', 'Clothes', 'Tops', 'pcs', 35000, 22000],
            ['Jeans', 'Clothes', 'Trousers', 'pcs', 45000, 28000], ['Dress', 'Clothes', 'Dresses', 'pcs', 50000, 30000],
            ['Skirt', 'Clothes', 'Skirts', 'pcs', 30000, 18000], ['Gomesi', 'Clothes', 'Traditional', 'pcs', 80000, 55000],
            ['Kanzu', 'Clothes', 'Traditional', 'pcs', 60000, 40000], ['Shoes (ladies)', 'Shoes', 'Ladies', 'pair', 60000, 38000],
            ['Shoes (men)', 'Shoes', 'Men', 'pair', 70000, 45000], ['Sandals', 'Shoes', 'Casual', 'pair', 15000, 9000],
            ['Handbag', 'Accessories', 'Bags', 'pcs', 45000, 28000], ['Belt', 'Accessories', 'Belts', 'pcs', 15000, 8000],
            ['School uniform set', 'Clothes', 'Uniforms', 'set', 40000, 27000],
        ],
        'electronics' => [
            ['Phone charger', 'Accessories', 'Chargers', 'pcs', 15000, 9000], ['USB cable', 'Accessories', 'Cables', 'pcs', 8000, 4000],
            ['Earphones', 'Accessories', 'Audio', 'pcs', 15000, 8000], ['Power bank 10,000mAh', 'Accessories', 'Power', 'pcs', 60000, 42000],
            ['Screen protector', 'Accessories', 'Protection', 'pcs', 10000, 3000], ['Phone cover', 'Accessories', 'Protection', 'pcs', 15000, 6000],
            ['Memory card 32GB', 'Storage', 'Memory cards', 'pcs', 25000, 17000], ['Flash disk 32GB', 'Storage', 'Flash disks', 'pcs', 30000, 20000],
            ['Feature phone', 'Phones', 'Feature phones', 'pcs', 60000, 45000], ['Smartphone (entry)', 'Phones', 'Smartphones', 'pcs', 350000, 300000],
            ['Bluetooth speaker', 'Audio', 'Speakers', 'pcs', 70000, 48000], ['Extension cable', 'Electrical', 'Power', 'pcs', 25000, 17000],
            ['Phone repair (screen)', 'Services', 'Repairs', 'service', 80000, 0], ['SIM card', 'Airtime', 'SIM cards', 'pcs', 2000, 1000],
        ],
        'poultry' => [
            ['Layers mash 70kg', 'Feeds', 'Layers', 'bag', 130000, 118000], ['Chick mash 50kg', 'Feeds', 'Chicks', 'bag', 115000, 104000],
            ['Growers mash 70kg', 'Feeds', 'Growers', 'bag', 120000, 108000], ['Broiler starter 50kg', 'Feeds', 'Broilers', 'bag', 125000, 113000],
            ['Broiler finisher 50kg', 'Feeds', 'Broilers', 'bag', 120000, 108000], ['Day-old chick (layer)', 'Birds', 'Chicks', 'pcs', 3800, 3200],
            ['Day-old chick (broiler)', 'Birds', 'Chicks', 'pcs', 3000, 2500], ['Broiler (live, 2kg)', 'Birds', 'Broilers', 'pcs', 18000, 13000],
            ['Spent layer hen', 'Birds', 'Layers', 'pcs', 15000, 11000], ['Eggs (tray of 30)', 'Eggs', 'Trays', 'tray', 12000, 9500],
            ['Eggs (piece)', 'Eggs', 'Loose', 'pcs', 500, 350], ['Empty egg trays (bundle of 100)', 'Supplies', 'Packaging', 'bundle', 25000, 20000],
            ['Newcastle vaccine (100 doses)', 'Veterinary', 'Vaccines', 'vial', 8000, 6000], ['Gumboro vaccine (100 doses)', 'Veterinary', 'Vaccines', 'vial', 9000, 7000],
            ['Coccidiostat 100g', 'Veterinary', 'Medicines', 'pcs', 10000, 7500], ['Poultry multivitamin 100g', 'Veterinary', 'Supplements', 'pcs', 6000, 4500],
            ['Dewormer (poultry) 100g', 'Veterinary', 'Medicines', 'pcs', 8000, 6000], ['Plastic feeder', 'Equipment', 'Feeders', 'pcs', 15000, 11000],
            ['Plastic drinker 5L', 'Equipment', 'Drinkers', 'pcs', 12000, 8500], ['Chicken manure (bag)', 'By-products', 'Manure', 'bag', 10000, 0],
        ],
        'other' => [
            ['Service (standard)', 'Services', 'General', 'service', 10000, 0], ['Delivery fee', 'Services', 'Delivery', 'service', 5000, 0],
            ['Photocopy (page)', 'Services', 'Printing', 'page', 200, 80], ['Printing (page)', 'Services', 'Printing', 'page', 500, 200],
            ['Lamination (A4)', 'Services', 'Printing', 'pcs', 3000, 1200], ['Phone charging', 'Services', 'General', 'service', 500, 0],
            ['Carrier bag', 'Supplies', 'Packaging', 'pcs', 200, 100], ['Water 500ml', 'Drinks', 'Water', 'pcs', 1000, 700],
            ['Soda 500ml', 'Drinks', 'Soft drinks', 'pcs', 1500, 1150], ['Bread 500g', 'Groceries', 'Bakery', 'pcs', 4500, 3900],
            ['Exercise book 96 pages', 'Stationery', 'Books', 'pcs', 1000, 750], ['Pen (blue)', 'Stationery', 'Pens', 'pcs', 500, 300],
            ['Batteries AA (pair)', 'Household', 'Lighting', 'pcs', 2000, 1500], ['Airtime 1,000', 'Airtime', 'Airtime', 'pcs', 1000, 950],
        ],
    ],
];

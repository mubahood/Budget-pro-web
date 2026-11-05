<?php

/*
| Estate Ledger's public demo (App\Services\Rentals\Demo\RentalsDemoBuilder, KR-21): "Ntinda Property Managers (Demo)", a
| letting agency in Kampala managing eight properties (120 units) for five landlords, and under `solo` "Mukasa Rentals
| (Demo)", a small landlord on his own with no agency (owner mode, 12 units). Everything here is made up: the people,
| the companies, their phone numbers (right format, in the +256 700 000 xxx block; nothing is ever sent from a demo
| account), the merchant code and the bank account (they read "demo" and "fictional"), the plots and the amounts.
| Amounts are in Uganda shillings.
|
| The agency moved its books in from another system on the first day of the history (`rentals.demo.months` ago): the
| landlords, properties, units and the tenancies running then come in through the import (RentalImport) with their
| opening figures; everything after that happens day by day through the services.
|
| Dates of the scenes are counted back from the build day: `ago` => [months, day of that month], `days_ago` => days.
| Every unit not in `empty_on_day_one` has a tenancy on the first day; `tenancies` holds the ones that are not an
| ordinary tenant paying on time. Pay: regular (within the grace days), parts (late, in two parts), behind:N (kept about
| N months behind, chased with calls and promises), ahead (six months at a time).
|
| The sign-ins are the demo's published credentials (the site's /demo page shows them): <key>@{rentals.demo.domain}
| with the password rentals.demo.password (config/rentals.php); the owner-mode account's are under `solo`.
*/
return [
    'agency' => [
        'name' => 'Ntinda Property Managers (Demo)',
        'address' => 'Plot 00, Ntinda–Kisaasi Road, Kampala (fictional)',
        'phone' => '+256 700 000 300',
        'email' => 'office',
        'plan' => 'estate-ledger-agency', // paid: the tenants' and landlords' own portal comes with a paid plan
        'phones' => ['tenant' => 256_700_000_400, 'kin' => 256_700_000_600],
        'settings' => ['mode' => 'agency', 'currency' => 'UGX', 'bill_days_before' => 5, 'grace_days' => 5, 'anchor' => 'start', 'prorate_first' => true,
            'commission_type' => 'percent', 'commission_bp' => 1000, 'commission_minor' => 0, 'commission_on_items' => false, 'deposit_months' => 1,
            'deposit_held_by' => 'agency', 'late_fee_type' => 'percent', 'late_fee_bp' => 500, 'late_fee_to' => 'agency', 'withholding_on' => true,
            'payment_instructions' => "Pay rent to Ntinda Property Managers (Demo):\n- MTN MoMo Pay, merchant code 000 000 (a demo code: not a real one)\n"
                ."- Bank: Demo Bank Uganda, Ntinda branch, account 0000 0000 0001 (fictional)\nWrite your unit and property as the reference, for example \"A2 Kisaasi Court\".",
            'receipt_footer' => 'Ntinda Property Managers (Demo): a sample account. Every name and number in it is made up.'],
    ],

    // Staff sign-ins, one per role. The account's owner opens the demo by default.
    'logins' => [
        'owner' => ['name' => 'Sarah Nantongo', 'role' => 'owner', 'label' => 'Account owner'],
        'manager' => ['name' => 'Rachel Namugga', 'role' => 'rentals_manager', 'label' => 'Property manager (agency staff)'],
        'accountant' => ['name' => 'Daniel Mugerwa', 'role' => 'rentals_accountant', 'label' => 'Accountant'],
        'caretaker' => ['name' => 'Moses Okello', 'role' => 'rentals_caretaker', 'label' => 'Caretaker (Najjera Rooms and Kiwatule Flats only)',
            'properties' => ['najjera', 'kiwatule']],
        'viewer' => ['name' => 'Tom Byaruhanga', 'role' => 'viewer', 'label' => 'Viewer (reads the figures and reports, changes nothing)'],
    ],

    // Staff who work in the books without a published sign-in: the other caretaker looks after more buildings.
    'staff' => [
        'caretaker2' => ['name' => 'Joseph Ouma', 'role' => 'rentals_caretaker', 'properties' => ['kisaasi', 'kyanja', 'kireka']],
    ],

    // Tenants' and landlords' own sign-ins (/my).
    'people' => [
        'tenant' => ['type' => 'tenant', 'tenancy' => 'kyanja:H6', 'label' => 'A tenant behind on rent'],
        'tenant-paid' => ['type' => 'tenant', 'tenancy' => 'kisaasi:A2', 'label' => 'A tenant who pays on time'],
        'landlord' => ['type' => 'landlord', 'landlord' => 'nakimuli', 'label' => 'A landlord'],
    ],

    'landlords' => [
        'nakimuli' => ['name' => 'Margaret Nakimuli', 'phone' => '+256700000201', 'address' => 'Kisaasi, Kampala (fictional)', 'payout_method' => 'bank',
            'payout_details' => 'Demo Bank Uganda, account 0000 0000 0101 (fictional)', 'opening' => 6_400_000],
        // A flat commission: 50,000 a unit for every month of rent collected.
        'lubowa' => ['name' => 'Lubowa Holdings Ltd (Demo)', 'phone' => '+256700000202', 'address' => 'Ntinda, Kampala (fictional)', 'payout_method' => 'bank',
            'payout_details' => 'Demo Bank Uganda, account 0000 0000 0102 (fictional)', 'commission_flat' => 50_000, 'opening' => 4_450_000],
        // Holds his tenants' deposits himself.
        'wasswa' => ['name' => 'Peter Wasswa', 'phone' => '+256700000203', 'address' => 'Najjera, Wakiso (fictional)', 'payout_method' => 'mobile_money',
            'payout_details' => 'MTN MoMo 0700 000 203 (fictional)', 'opening' => 2_100_000],
        // A lower percent, agreed for her one building.
        'atim' => ['name' => 'Grace Atim', 'phone' => '+256700000204', 'address' => 'Bukoto, Kampala (fictional)', 'payout_method' => 'bank',
            'payout_details' => 'Demo Bank Uganda, account 0000 0000 0104 (fictional)', 'opening' => 1_800_000, 'commission_percent' => 8],
        'ssenoga' => ['name' => 'Ssenoga Investments Ltd (Demo)', 'phone' => '+256700000205', 'address' => 'Kiwatule, Kampala (fictional)', 'payout_method' => 'bank',
            'payout_details' => 'Demo Bank Uganda, account 0000 0000 0105 (fictional)', 'opening' => 1_250_000],
    ],

    // units: [code, kind, rent a month, months per bill, bedrooms]
    'properties' => [
        'kisaasi' => ['name' => 'Kisaasi Court', 'landlord' => 'nakimuli', 'kind' => 'residential', 'area' => 'Kisaasi', 'district' => 'Kampala',
            'address' => 'Kisaasi Road (fictional plot)', 'units' => array_merge(
                array_map(fn ($n) => ["A{$n}", 'apartment', 650_000, 1, 2], range(1, 12)),
                array_map(fn ($n) => ["B{$n}", 'apartment', 650_000, 1, 2], range(1, 12)))],
        'kyanja' => ['name' => 'Kyanja Heights', 'landlord' => 'nakimuli', 'kind' => 'residential', 'area' => 'Kyanja', 'district' => 'Kampala',
            'address' => 'Kyanja Ring Road (fictional plot)', 'units' => array_map(fn ($n) => ["H{$n}", 'apartment', 400_000, 1, 1], range(1, 14))],
        'ntinda' => ['name' => 'Ntinda Shops', 'landlord' => 'lubowa', 'kind' => 'commercial', 'area' => 'Ntinda', 'district' => 'Kampala',
            'address' => 'Ntinda Trading Centre (fictional plot)', 'units' => array_map(
                fn ($n) => ["S{$n}", 'shop', 400_000 + $n * 50_000, $n % 4 === 2 ? 3 : 1, null], range(1, 14))],
        'lubowa' => ['name' => 'Lubowa House', 'landlord' => 'lubowa', 'kind' => 'commercial', 'area' => 'Ntinda', 'district' => 'Kampala',
            'address' => 'Ntinda Complex Road (fictional plot)', 'units' => array_map(
                fn ($n) => ["O{$n}", 'office', 900_000 + $n * 50_000, $n % 3 === 0 ? 3 : 1, null], range(1, 12))],
        'najjera' => ['name' => 'Najjera Rooms', 'landlord' => 'wasswa', 'kind' => 'residential', 'area' => 'Najjera', 'district' => 'Wakiso',
            'address' => 'Najjera–Kira Road (fictional plot)', 'deposit_held_by' => 'landlord', 'water_rate' => 5_000, 'meters' => ['water'], 'units' => array_merge(
                array_map(fn ($n) => ["R{$n}", 'single_room', 150_000, 1, 1], range(1, 14)),
                array_map(fn ($n) => ["R{$n}", 'double_room', 250_000, 1, 1], range(15, 22)))],
        'bukoto' => ['name' => 'Bukoto Apartments', 'landlord' => 'atim', 'kind' => 'residential', 'area' => 'Bukoto', 'district' => 'Kampala',
            'address' => 'Bukoto Crescent (fictional plot)', 'items' => 'Security:50000', 'units' => array_map(
                fn ($n) => ["V{$n}", 'apartment', 1_500_000 + $n * 100_000, 3, 3], range(1, 6))],
        'kiwatule' => ['name' => 'Kiwatule Flats', 'landlord' => 'ssenoga', 'kind' => 'residential', 'area' => 'Kiwatule', 'district' => 'Kampala',
            'address' => 'Kiwatule Road (fictional plot)', 'items' => 'Garbage:10000:agency', 'water_rate' => 5_000, 'power_rate' => 800, 'meters' => ['water', 'power'],
            'commission_percent' => 12, 'units' => array_map(fn ($n) => ["F{$n}", 'studio', 300_000, 1, 0], range(1, 14))],
        'kireka' => ['name' => 'Kireka Rooms', 'landlord' => 'ssenoga', 'kind' => 'residential', 'area' => 'Kireka', 'district' => 'Wakiso',
            'address' => 'Kireka–Namugongo Road (fictional plot)', 'items' => 'Garbage:5000:agency', 'units' => array_map(fn ($n) => ["K{$n}", 'single_room', 200_000, 1, 1], range(1, 14))],
    ],

    // Names: people pair a first name with a surname by their index; companies rent the shops and offices.
    'names' => [
        'first' => ['Brenda', 'Ivan', 'Agnes', 'Ronald', 'Juliet', 'Samuel', 'Patience', 'Hassan', 'Esther', 'Collins', 'Winnie', 'Dennis', 'Gloria', 'Allan', 'Prossy', 'Edgar',
            'Martha', 'Joel', 'Sharon', 'Isaac', 'Lillian', 'Henry', 'Faith', 'Arthur', 'Rebecca', 'Kenneth', 'Doreen', 'Abdul', 'Christine', 'Paul', 'Annet', 'Godfrey', 'Diana',
            'Robert', 'Mercy', 'Francis', 'Scovia', 'Emmanuel', 'Josephine', 'Benon', 'Irene', 'Ambrose', 'Harriet', 'Simon', 'Ruth', 'David', 'Caroline', 'Brian', 'Phiona',
            'Timothy', 'Stella', 'Alex', 'Teddy', 'Herbert', 'Jackline', 'Nicholas', 'Sylvia', 'Vincent', 'Damalie', 'Oscar', 'Betty'],
        'last' => ['Nakato', 'Ssemwogerere', 'Akello', 'Tumusiime', 'Nabukeera', 'Odongo', 'Ainembabazi', 'Ssali', 'Nanyonga', 'Byamukama', 'Auma', 'Kisakye', 'Natukunda', 'Mutyaba',
            'Nalwanga', 'Kyeyune', 'Apio', 'Turyamureeba', 'Kemigisha', 'Lubega', 'Nansubuga', 'Okot', 'Namirembe', 'Ssebunya', 'Atuhaire', 'Opio', 'Nakibuuka', 'Kasozi', 'Adong',
            'Mwesigye', 'Nambooze', 'Ochieng', 'Kyomuhendo', 'Kiggundu', 'Achan', 'Bwambale', 'Nakamya', 'Wandera', 'Ayebare', 'Kakooza', 'Nabatanzi', 'Ogwal', 'Nankinga', 'Rukundo',
            'Nalubega', 'Ssekandi', 'Namuddu', 'Asiimwe', 'Nantume', 'Kaweesa', 'Akullo', 'Mugisha', 'Namatovu', 'Tusiime', 'Kabuye', 'Nassozi', 'Baguma', 'Nakalema', 'Otim'],
        'companies' => ['Ntinda Hardware Ltd (Demo)', 'Kampala Phone Hub (Demo)', 'Bright Pharmacy (Demo)', 'Bites & Brew Café (Demo)', 'Glow Beauty Salon (Demo)', 'QuickPrint Stationers (Demo)',
            'Sunrise Boutique (Demo)', 'Fresh Valley Grocers (Demo)', 'Peak Electricals (Demo)', 'Trendy Shoes (Demo)', 'Grace Tailoring House (Demo)', 'Metro Forex Bureau (Demo)',
            'City Optical Centre (Demo)', 'Harvest Agro Supplies (Demo)', 'Lakeview Consultants (Demo)', 'Summit Auditors (Demo)', 'Nile Freight Ltd (Demo)', 'Roadline Works (Demo)',
            'Pearl Insurance Brokers (Demo)', 'Kaggwa & Co Advocates (Demo)', 'Horizon Travel (Demo)', 'BlueDot Software (Demo)', 'Kampala College of Business (Demo)', 'Mirembe Clinic (Demo)',
            'Unity Microfinance (Demo)', 'Crestline Surveyors (Demo)', 'Sable Architects (Demo)', 'Print Corner (Demo)', 'Zawadi Gifts (Demo)', 'Crane Interiors (Demo)'],
    ],
    'occupations' => [['Teacher', 'Lakeside Primary School (demo)'], ['Nurse', 'Mirembe Clinic (demo)'], ['Accountant', 'Nile Freight Ltd (demo)'], ['Trader', 'Self-employed'],
        ['Civil engineer', 'Roadline Works (demo)'], ['Bank officer', 'Demo Bank Uganda'], ['Software developer', 'Self-employed'], ['Lab technician', 'Mirembe Clinic (demo)'],
        ['Driver', 'Nile Freight Ltd (demo)'], ['Tailor', 'Self-employed'], ['Lecturer', 'Kampala College of Business (demo)'], ['Sales agent', 'Roadline Works (demo)']],
    'kin' => ['Joseph', 'Sarah', 'Paul', 'Agnes', 'Robert', 'Mary', 'Charles', 'Jane', 'Vincent', 'Rose'],

    // Units with nobody in them on the first day (some are let later: `move_ins`).
    'empty_on_day_one' => ['kisaasi:A12', 'kisaasi:B11', 'kyanja:H13', 'kyanja:H14', 'ntinda:S13', 'ntinda:S14', 'lubowa:O11', 'lubowa:O12',
        'najjera:R20', 'najjera:R21', 'najjera:R22', 'kiwatule:F13', 'kiwatule:F14', 'kireka:K13', 'kireka:K14'],

    /*
    | The tenancies that are not simply an ordinary tenant paying on time. Optional fields: since (months before the first
    | day), day (of the month they started), pay, m (momo | cash | bank), owed and credit (opening figures), deposit (the
    | deposit held, when not a month's rent), same_tenant_as, early (always pays days ahead), ends_in (a fixed term ending
    | in N days), ended_days_ago (the term ran out and the tenant stayed), renew (a term that ended and was renewed).
    */
    'tenancies' => [
        // Tenants who pay the landlord at the gate: the agency records it, and only its commission moves.
        'kyanja:H11' => ['since' => 16, 'day' => 1, 'pay' => 'direct', 'm' => 'cash'],
        'kiwatule:F11' => ['since' => 14, 'day' => 1, 'pay' => 'direct', 'm' => 'cash'],

        // The tenant sign-ins: one who pays days ahead of time, one kept two months behind.
        'kisaasi:A2' => ['since' => 20, 'day' => 5, 'pay' => 'regular', 'm' => 'momo', 'early' => true],
        'kyanja:H6' => ['since' => 30, 'day' => 1, 'pay' => 'behind:2', 'm' => 'momo', 'owed' => 800_000],

        // Paid ahead, six months at a time.
        'kisaasi:B1' => ['since' => 15, 'day' => 1, 'pay' => 'ahead', 'm' => 'bank', 'credit' => 1_300_000],
        'kyanja:H2' => ['since' => 13, 'day' => 1, 'pay' => 'ahead', 'm' => 'bank', 'credit' => 800_000],
        'kiwatule:F5' => ['since' => 11, 'day' => 1, 'pay' => 'ahead', 'm' => 'momo', 'credit' => 300_000],
        'kiwatule:F1' => ['since' => 15, 'day' => 1, 'm' => 'momo', 'credit' => 100_000],

        // Behind by different amounts on different days of the month, so each aging bucket holds something on any day.
        'ntinda:S3' => ['since' => 26, 'day' => 1, 'pay' => 'behind:5', 'm' => 'momo', 'owed' => 1_100_000],
        'kireka:K4' => ['since' => 16, 'day' => 15, 'pay' => 'behind:4', 'm' => 'cash', 'owed' => 400_000],
        'najjera:R14' => ['since' => 10, 'day' => 25, 'pay' => 'behind:3', 'm' => 'momo', 'owed' => 150_000],
        'lubowa:O5' => ['since' => 12, 'day' => 10, 'pay' => 'behind:2', 'm' => 'bank'],
        'najjera:R12' => ['since' => 21, 'day' => 1, 'pay' => 'behind:1', 'm' => 'cash', 'owed' => 150_000],
        'kisaasi:B10' => ['since' => 9, 'day' => 20, 'pay' => 'behind:1', 'm' => 'momo'],

        // Late, in two parts.
        'kisaasi:A6' => ['since' => 9, 'day' => 15, 'pay' => 'parts', 'm' => 'momo', 'owed' => 325_000],
        'kisaasi:B4' => ['since' => 12, 'day' => 25, 'pay' => 'parts', 'm' => 'momo'],
        'ntinda:S6' => ['since' => 14, 'day' => 15, 'pay' => 'parts', 'm' => 'momo'],
        'lubowa:O7' => ['since' => 24, 'day' => 1, 'pay' => 'parts', 'm' => 'bank'],
        'najjera:R4' => ['since' => 12, 'day' => 15, 'pay' => 'parts', 'm' => 'cash', 'deposit' => 100_000],
        'bukoto:V3' => ['since' => 30, 'day' => 1, 'pay' => 'parts', 'm' => 'bank'],
        'kiwatule:F4' => ['since' => 18, 'day' => 15, 'pay' => 'parts', 'm' => 'momo'],
        'kireka:K8' => ['since' => 7, 'day' => 5, 'pay' => 'parts', 'm' => 'cash'],

        // One company renting two shops.
        'ntinda:S1' => ['since' => 36, 'day' => 1, 'm' => 'bank'],
        'ntinda:S2' => ['since' => 36, 'day' => 1, 'm' => 'bank', 'same_tenant_as' => 'ntinda:S1'],

        // A smaller deposit than a month's rent.
        'najjera:R1' => ['since' => 26, 'day' => 1, 'm' => 'cash', 'deposit' => 100_000],
        'najjera:R2' => ['since' => 20, 'day' => 5, 'm' => 'cash', 'deposit' => 100_000, 'owed' => 75_000],

        // Rent goes up at the anniversary three months ago (start: its month, two years back).
        'kisaasi:A3' => ['anniversary_ago' => 3, 'years' => 2, 'm' => 'bank', 'rent_to' => 700_000],

        // Fixed terms: ending soon, ended with the tenant still there, renewed.
        'kisaasi:A4' => ['since' => 18, 'day' => 10, 'm' => 'cash', 'ends_in' => 55],
        'ntinda:S4' => ['since' => 22, 'day' => 10, 'm' => 'momo', 'ends_in' => 22],
        'bukoto:V2' => ['since' => 12, 'day' => 15, 'm' => 'bank', 'ends_in' => 120],
        'kyanja:H1' => ['since' => 20, 'day' => 1, 'm' => 'bank', 'ended_days_ago' => 20],
        'kiwatule:F6' => ['since' => 6, 'day' => 20, 'm' => 'cash', 'ended_days_ago' => 9],
        'kisaasi:B2' => ['since' => 40, 'day' => 20, 'm' => 'momo', 'renew' => ['ago' => [5, 19], 'months' => 12]],
        'ntinda:S8' => ['since' => 18, 'day' => 1, 'm' => 'bank', 'renew' => ['ago' => [2, 28], 'months' => null]],

        // Those who leave owing (see `move_outs`).
        'kisaasi:B12' => ['since' => 10, 'day' => 1, 'pay' => 'behind:3', 'm' => 'momo', 'owed' => 650_000],
        'najjera:R5' => ['since' => 11, 'day' => 1, 'pay' => 'behind:2', 'm' => 'cash', 'deposit' => 100_000],
    ],

    // Opening cash on the first day, per account.
    'opening_cash' => ['cash' => 3_000_000, 'bank' => 62_000_000, 'mobile_money' => 6_000_000],

    // New tenants moving into units empty on the first day.
    'move_ins' => [
        'najjera:R20' => ['ago' => [11, 10], 'pay' => 'regular', 'm' => 'cash'],
        'kisaasi:B11' => ['ago' => [10, 3], 'pay' => 'regular', 'm' => 'momo'],
        'kiwatule:F13' => ['ago' => [9, 1], 'pay' => 'regular', 'm' => 'momo'],
        'kyanja:H13' => ['ago' => [8, 15], 'pay' => 'regular', 'm' => 'momo'],
        'lubowa:O11' => ['ago' => [7, 1], 'pay' => 'regular', 'm' => 'bank'],
        'ntinda:S13' => ['ago' => [6, 1], 'pay' => 'regular', 'm' => 'momo'],
        'kireka:K13' => ['ago' => [5, 12], 'pay' => 'regular', 'm' => 'cash'],
        'kisaasi:A12' => ['ago' => [3, 1], 'pay' => 'regular', 'm' => 'bank'],
        'najjera:R21' => ['ago' => [2, 7], 'pay' => 'regular', 'm' => 'momo'],
    ],

    /*
    | Move-outs: clean ones, two who leave owing (the deposit settles part), damage and final water readings taken off the
    | deposit, one whose deposit is kept for now, and one whose deposit stayed with us and went back weeks later
    | (`refund_later`, days after the move-out). A re-let comes `after` days later.
    */
    'move_outs' => [
        'kisaasi:A5' => ['ago' => [9, 28], 'refund_deposit' => 'mobile_money', 'reason' => 'Bought a house in Gayaza',
            'relet' => ['after' => 42, 'pay' => 'regular', 'm' => 'momo']],
        'najjera:R5' => ['ago' => [8, 15], 'refund_deposit' => 'cash', 'reason' => 'Left owing rent; the deposit settled part of it', 'water' => 3.5,
            'relet' => ['after' => 21, 'pay' => 'regular', 'm' => 'cash']],
        'ntinda:S7' => ['ago' => [6, 20], 'refund_deposit' => 'bank', 'damages' => [['Roller shutter repaired at move-out', 250_000]], 'reason' => 'Moved the shop to Kireka',
            'relet' => ['after' => 58, 'pay' => 'regular', 'm' => 'bank']],
        'kiwatule:F9' => ['ago' => [7, 3], 'refund_deposit' => 'mobile_money', 'water' => 4.2, 'reason' => 'Went to study abroad',
            'relet' => ['after' => 30, 'pay' => 'regular', 'm' => 'momo']],
        'kisaasi:B12' => ['ago' => [5, 12], 'refund_deposit' => 'mobile_money', 'reason' => 'Left owing three months of rent; the deposit settled part of it'],
        'kisaasi:A10' => ['ago' => [4, 8], 'refund_deposit' => 'bank', 'reason' => 'Moved to Entebbe for work'],
        'najjera:R17' => ['ago' => [3, 28], 'refund_deposit' => 'cash', 'water' => 2.8, 'reason' => 'Got married and moved to Gayaza'],
        'lubowa:O9' => ['ago' => [3, 18], 'refund_deposit' => 'bank', 'damages' => [['Glass partition broken at move-out', 400_000]], 'reason' => 'Firm closed its Kampala office'],
        'kireka:K5' => ['ago' => [3, 2], 'reason' => 'Left for Jinja. The deposit went back once the final bills were agreed', 'refund_later' => ['after' => 26, 'method' => 'mobile_money']],
        'kyanja:H8' => ['ago' => [2, 25], 'reason' => 'Moved to Mukono. The deposit stays with us until the final bills are agreed'],
        'ntinda:S12' => ['ago' => [2, 10], 'refund_deposit' => 'bank', 'reason' => 'Closed the shop'],
        'kireka:K6' => ['ago' => [1, 20], 'refund_deposit' => 'cash', 'reason' => 'Moved closer to work'],
        'najjera:R18' => ['days_ago' => 24, 'refund_deposit' => 'cash', 'water' => 3.1, 'damages' => [['Door lock and window pane replaced', 85_000]], 'reason' => 'Moved to Kira'],
        'kiwatule:F3' => ['days_ago' => 35, 'refund_deposit' => 'mobile_money', 'unused_days' => true, 'water' => 5.5,
            'damages' => [['Repainting the walls', 120_000]], 'reason' => 'Transferred to work in Mbarara'],
    ],

    // A lease typed in by mistake and cancelled before any money moved (the unit stays free).
    'cancelled' => [['unit' => 'najjera:R22', 'ago' => [3, 9], 'reason' => 'The tenant changed his mind before paying anything']],

    // Notice given: [days ago, moving out in days].
    'notices' => ['kyanja:H3' => [12, 18], 'najjera:R11' => [5, 25], 'lubowa:O2' => [20, 40]],

    // Rent changes, from the next period not yet billed at the time.
    'rent_changes' => [
        ['ntinda:S6', 750_000, ['ago' => [6, 10]], 'Yearly review agreed with the landlord'],
        ['najjera:R3', 170_000, ['ago' => [4, 20]], 'New rent after the water tank was added'],
        ['bukoto:V1', 1_750_000, ['ago' => [8, 5]], 'Second year of the lease'],
        ['lubowa:O1', 1_050_000, ['ago' => [5, 3]], 'Yearly review agreed with the landlord'],
    ],

    // Fees beside rent started and stopped on one lease.
    'items' => [
        ['add', 'kisaasi:A1', 'Parking', 50_000, ['ago' => [6, 2]]],
        ['add', 'kisaasi:B3', 'Parking', 50_000, ['ago' => [9, 2]]],
        ['end', 'kisaasi:B3', 'Parking', null, ['ago' => [3, 2]]],
    ],

    // One-off bills: [lease, what, amount, goes to, when, (optional) why it was cancelled the same day].
    'one_offs' => [
        ['kisaasi:A1', 'Replacement gate key', 30_000, 'landlord', ['ago' => [11, 12]]],
        ['najjera:R3', 'Broken door lock charged to the tenant', 35_000, 'landlord', ['ago' => [10, 18]]],
        ['bukoto:V2', 'Tenancy agreement renewal fee', 100_000, 'agency', ['ago' => [10, 7]]],
        ['ntinda:S4', 'Signboard bracket repair', 45_000, 'landlord', ['ago' => [8, 22]]],
        ['kiwatule:F2', 'Replacement access card', 20_000, 'agency', ['ago' => [6, 16]]],
        ['lubowa:O3', 'Extra office keys cut', 40_000, 'landlord', ['ago' => [5, 11]]],
        ['kyanja:H4', 'Replacement gate key', 30_000, 'landlord', ['ago' => [4, 13]]],
        ['kisaasi:A3', 'Broken window pane charged to the tenant', 60_000, 'landlord', ['days_ago' => 14]],
        ['kisaasi:A4', 'Replacement gate key', 30_000, 'landlord', ['ago' => [7, 9]], 'Typed in twice: the first bill stands'],
        ['najjera:R6', 'Water bill typed in by hand', 40_000, 'landlord', ['ago' => [5, 21]], 'The meter reading already billed this'],
    ],

    // Forgiven: [lease, what kind of bill, how much at most, when, why].
    'waivers' => [
        ['najjera:R12', 'rent', 100_000, ['ago' => [5, 16]], 'The landlord forgave part of the month the room had no water'],
        ['ntinda:S3', 'late_fee', 40_000, ['days_ago' => 25], 'Late fee forgiven: the delay was the bank\'s'],
    ],

    // Payments reversed, each with its reason: a cheque that bounced (paid again by bank days later), and money entered
    // on the wrong tenant.
    'bounced_cheque' => ['lease' => 'ntinda:S2', 'ago' => [7, 1], 'reason' => 'Cheque returned unpaid by the bank'],
    'wrong_tenant' => ['wrong' => 'kisaasi:B3', 'right' => 'kisaasi:A1', 'amount' => 650_000, 'days_ago' => 41, 'reason' => 'Entered on the wrong tenant: it belongs to A1'],

    // A landlord's payout sent to the wrong account, reversed and paid again the next day.
    'reversed_payout' => ['landlord' => 'nakimuli', 'ago' => [3, 6], 'reason' => 'Sent to the old account number: the bank returned it'],

    // A former tenant paying part of what they owe.
    'former_pays' => [['najjera:R5', 50_000, 'cash', ['ago' => [3, 14]]]],

    // Units not for letting today (no tenant in them): held for someone, or under repair.
    'unit_states' => [
        'kisaasi:B12' => ['status' => 'reserved', 'notes' => 'Held for a new tenant moving in at the start of next month'],
        'kireka:K14' => ['status' => 'reserved', 'notes' => 'Held for a nurse starting at the clinic next month'],
        'kyanja:H8' => ['status' => 'maintenance', 'notes' => 'Bathroom being redone after the last tenant left'],
        'lubowa:O9' => ['status' => 'maintenance', 'notes' => 'Glass partition being replaced'],
        'kiwatule:F14' => ['status' => 'in_use', 'in_use_by' => 'Joseph Ouma, the caretaker', 'notes' => 'Lives on site'],
        'ntinda:S14' => ['status' => 'in_use', 'in_use_by' => 'Lubowa Holdings, as a store', 'notes' => 'The landlord keeps stock here'],
    ],

    /*
    | The landlord and commission work, as an agency really lives it. Every name, figure and reason is made up. `close_months`: months are closed by the 25th of the next
    | month, from this many months back, so the month just ended is usually still there to close by hand and the older ones have frozen statements. Dates are `ago` [months, day].
    */
    'landlord_work' => [
        'close_months' => 4,
        'reissue' => ['ago' => [2, 26], 'month_ago' => 3, 'property' => 'kisaasi', 'reason' => 'A bill for this month was found after it closed', 'category' => 'Cleaning', 'vendor' => 'cleaning', 'what' => 'Water tank cleaned, bill found late', 'amount' => 85_000],
        'late_receipt' => ['lease' => 'kyanja:H6', 'ago' => [1, 26], 'month_ago' => 2, 'amount' => 100_000],
        'handover' => ['property' => 'kireka', 'to' => 'atim', 'ago' => [4, 1], 'reason' => 'Sold by Ssenoga Investments to Grace Atim'],
        'unit_rule' => ['property' => 'ntinda', 'unit' => 'S13', 'percent' => 12, 'ago' => [7, 15]],
        'lease_rate' => ['lease' => 'kisaasi:A7', 'percent' => 8, 'ago' => [5, 20], 'from' => [3, 1], 'reason' => 'Agreed with the landlord: this tenant pays for the whole year'],
        'landlord_rate' => ['landlord' => 'atim', 'percent' => 7, 'ago' => [4, 20], 'from' => [3, 1], 'reason' => 'New rate agreed for all her buildings'],
        'bill_ahead' => [['lease' => 'kisaasi:A8', 'periods' => 3, 'ago' => [2, 3]], ['lease' => 'kiwatule:F7', 'periods' => 2, 'ago' => [1, 2]]],
        'repair_limit' => ['landlord' => 'nakimuli', 'limit' => 1_000_000, 'ago' => [3, 1]],
        'repairs' => [
            ['unit' => 'kisaasi:A9', 'title' => 'Water pump replaced', 'ago' => [2, 8], 'quote' => 1_200_000, 'yes' => 'Margaret Nakimuli', 'done' => 3, 'by' => 'Kato Plumbing Works (demo)'],
            ['unit' => 'kisaasi:B5', 'title' => 'Burst pipe in the ceiling', 'ago' => [1, 12], 'quote' => 1_100_000, 'done' => 1, 'by' => 'Kato Plumbing Works (demo)', 'reason' => 'Water was coming through the ceiling and the landlord could not be reached'],
            ['unit' => 'kyanja:H9', 'title' => 'Roof over the stairs leaking', 'days_ago' => 5, 'quote' => 1_400_000, 'ask' => true],
        ],
        'hold_back' => ['landlord' => 'lubowa', 'percent' => 5, 'ago' => [6, 2], 'paid' => [['for_ago' => 3, 'ago' => [2, 10]], ['for_ago' => 2, 'ago' => [1, 10]]]],
        'advance' => ['landlord' => 'wasswa', 'over' => 150_000, 'ago' => [1, 18], 'reason' => 'Asked for school fees ahead of the next payout'],
        'pays_twice' => 'nakimuli', // a second payout around the 20th, when enough has come in since the 5th
    ],

    // Repairs: where, what, priority, who reported it, when, and how it ended (done after N days with a cost, open,
    // in progress or cancelled). Costs are charged to the landlord unless 'agency'.
    'maintenance' => [
        ['kisaasi:A4', 'Kitchen sink blocked', 'normal', 'tenant', ['ago' => [11, 6]], ['done' => 2, 'cost' => 60_000, 'by' => 'Kato Plumbing Works (demo)']],
        ['najjera:R3', 'Door lock broken', 'normal', 'staff', ['ago' => [10, 17]], ['done' => 1, 'cost' => 35_000, 'by' => 'Musa the carpenter (demo)']],
        ['bukoto:V2', 'Gate motor not working', 'high', 'tenant', ['ago' => [10, 9]], ['done' => 4, 'cost' => 450_000, 'by' => 'Gatemasters Uganda (demo)']],
        ['ntinda:S4', 'Roller shutter stuck', 'high', 'tenant', ['ago' => [9, 21]], ['done' => 2, 'cost' => 180_000, 'by' => 'Ntinda Welders (demo)']],
        ['kyanja:H5', 'Water heater not heating', 'normal', 'tenant', ['ago' => [9, 4]], ['done' => 5, 'cost' => 320_000, 'by' => 'Kato Plumbing Works (demo)']],
        ['kiwatule:F2', 'Ceiling leaking in the rain', 'high', 'staff', ['ago' => [8, 12]], ['done' => 6, 'cost' => 280_000, 'by' => 'Roofline Repairs (demo)']],
        ['najjera', 'Septic tank emptied', 'normal', 'staff', ['ago' => [8, 3]], ['done' => 3, 'cost' => 400_000, 'by' => 'Clean Pit Services (demo)']],
        ['kisaasi:B2', 'Socket sparks in the bedroom', 'urgent', 'tenant', ['ago' => [7, 14]], ['done' => 1, 'cost' => 90_000, 'by' => 'Musa Electricals (demo)']],
        ['lubowa', 'Lift stuck on the second floor', 'urgent', 'staff', ['ago' => [6, 22]], ['done' => 3, 'cost' => 950_000, 'by' => 'Uplift Services (demo)']],
        ['ntinda', 'Security lights at the parking', 'normal', 'staff', ['ago' => [6, 8]], ['done' => 7, 'cost' => 150_000, 'by' => 'Musa Electricals (demo)']],
        ['kyanja:H1', 'Bathroom tiles cracked', 'low', 'tenant', ['ago' => [5, 19]], ['cancel' => 'The tenant fixed it with their own fundi']],
        ['bukoto:V1', 'Garden hedge overgrown', 'low', 'staff', ['ago' => [4, 11]], ['done' => 10, 'cost' => 120_000, 'by' => 'Green Hands Gardeners (demo)']],
        ['najjera:R8', 'Window pane broken', 'normal', 'tenant', ['ago' => [3, 7]], ['done' => 3, 'cost' => 45_000, 'by' => 'Musa the carpenter (demo)']],
        ['kiwatule', 'Garbage skip damaged', 'normal', 'staff', ['ago' => [2, 16]], ['done' => 2, 'cost' => 70_000, 'by' => 'Kiwatule Welders (demo)', 'agency' => true]],
        ['kisaasi:A6', 'Wardrobe door off its hinges', 'low', 'tenant', ['ago' => [1, 22]], ['done' => 4, 'cost' => 50_000, 'by' => 'Musa the carpenter (demo)']],
        ['ntinda:S6', 'Toilet cistern leaking', 'normal', 'tenant', ['days_ago' => 20], ['progress' => 'Kato Plumbing Works (demo)']],
        ['lubowa:O4', 'Air conditioner dripping', 'normal', 'tenant', ['days_ago' => 12], ['progress' => 'CoolAir Services (demo)']],
        ['kyanja:H4', 'Balcony door does not close', 'normal', 'tenant', ['days_ago' => 9], []],
        ['kisaasi:B3', 'Power keeps tripping in the evening', 'high', 'tenant', ['days_ago' => 6], []],
        ['najjera:R7', 'Burst water pipe flooding the room', 'urgent', 'tenant', ['days_ago' => 2], []],
    ],

    // Who the agency pays for what (every name made up).
    'vendors' => [
        'security' => 'Guardline Security (demo)', 'garbage' => 'City Clean Team (demo)', 'water' => 'Water utility (demo account)',
        'cleaning' => 'Sparkle Cleaners (demo)', 'rates' => 'City authority (demo)', 'power' => 'Power utility (demo account)', 'tax' => 'Revenue office (demo)',
        'office' => 'Ntinda Plaza (demo)', 'printer' => 'Print Corner (demo)', 'advocate' => 'Kaggwa & Co Advocates (demo)',
    ],

    // What is paid every month: on which day, for which property (its landlord pays) or the agency itself.
    'monthly_expenses' => [
        ['day' => 1, 'category' => 'Agency office', 'vendor' => 'office', 'what' => 'Office rent', 'amount' => 1_200_000, 'method' => 'bank', 'charged_to' => 'agency'],
        ['day' => 3, 'property' => 'kisaasi', 'category' => 'Security', 'vendor' => 'security', 'what' => 'Night guards', 'amount' => 700_000, 'method' => 'bank'],
        ['day' => 3, 'property' => 'kyanja', 'category' => 'Security', 'vendor' => 'security', 'what' => 'Night guard', 'amount' => 350_000, 'method' => 'bank'],
        ['day' => 3, 'property' => 'ntinda', 'category' => 'Security', 'vendor' => 'security', 'what' => 'Parking guard', 'amount' => 350_000, 'method' => 'bank'],
        ['day' => 3, 'property' => 'lubowa', 'category' => 'Security', 'vendor' => 'security', 'what' => 'Reception and night guards', 'amount' => 800_000, 'method' => 'bank'],
        ['day' => 4, 'property' => 'kisaasi', 'category' => 'Cleaning', 'vendor' => 'cleaning', 'what' => 'Stairs and compound', 'amount' => 200_000, 'method' => 'mobile_money'],
        ['day' => 4, 'property' => 'lubowa', 'category' => 'Cleaning', 'vendor' => 'cleaning', 'what' => 'Corridors and washrooms', 'amount' => 300_000, 'method' => 'mobile_money'],
        ['day' => 4, 'property' => 'ntinda', 'category' => 'Garbage', 'vendor' => 'garbage', 'what' => 'Skip collection', 'amount' => 80_000, 'method' => 'cash'],
        ['day' => 4, 'property' => 'najjera', 'category' => 'Garbage', 'vendor' => 'garbage', 'what' => 'Skip collection', 'amount' => 60_000, 'method' => 'cash'],
        ['day' => 4, 'property' => 'kiwatule', 'category' => 'Garbage', 'vendor' => 'garbage', 'what' => 'Collection the garbage fee pays for', 'amount' => 90_000, 'method' => 'cash', 'charged_to' => 'agency'],
        ['day' => 4, 'property' => 'kireka', 'category' => 'Garbage', 'vendor' => 'garbage', 'what' => 'Collection the garbage fee pays for', 'amount' => 50_000, 'method' => 'cash', 'charged_to' => 'agency'],
        ['day' => 8, 'property' => 'najjera', 'category' => 'Water', 'vendor' => 'water', 'what' => 'Bulk water bill', 'amount' => 280_000, 'method' => 'mobile_money'],
        ['day' => 8, 'property' => 'kiwatule', 'category' => 'Water', 'vendor' => 'water', 'what' => 'Bulk water bill', 'amount' => 170_000, 'method' => 'mobile_money'],
        ['day' => 9, 'property' => 'kiwatule', 'category' => 'Electricity', 'vendor' => 'power', 'what' => 'Bulk power bill', 'amount' => 260_000, 'method' => 'mobile_money'],
        ['day' => 10, 'category' => 'Transport', 'what' => 'Site visits', 'amount' => 200_000, 'method' => 'cash', 'charged_to' => 'agency'],
        ['day' => 10, 'category' => 'Other', 'what' => 'Airtime and data', 'amount' => 120_000, 'method' => 'mobile_money', 'charged_to' => 'agency'],
        ['day' => 28, 'category' => 'Salaries', 'what' => 'Office and caretakers', 'amount' => 2_800_000, 'method' => 'bank', 'charged_to' => 'agency'],
    ],

    // Paid once. `reversed` is an expense entered by mistake and reversed the day after.
    'one_expenses' => [
        ['when' => ['ago' => [11, 14]], 'property' => 'kyanja', 'category' => 'Property rates', 'vendor' => 'rates', 'what' => 'Rates for the year', 'amount' => 600_000, 'method' => 'bank'],
        ['when' => ['ago' => [9, 20]], 'property' => 'ntinda', 'category' => 'Rental income tax', 'vendor' => 'tax', 'what' => 'Instalment paid for the landlord', 'amount' => 850_000, 'method' => 'bank'],
        ['when' => ['ago' => [7, 11]], 'category' => 'Legal', 'vendor' => 'advocate', 'what' => 'Tenancy agreement template reviewed', 'amount' => 150_000, 'method' => 'bank', 'charged_to' => 'agency'],
        ['when' => ['ago' => [5, 6]], 'category' => 'Other', 'vendor' => 'printer', 'what' => 'Receipt books and notices', 'amount' => 70_000, 'method' => 'cash', 'charged_to' => 'agency'],
        ['when' => ['ago' => [2, 11]], 'property' => 'kisaasi', 'category' => 'Cleaning', 'vendor' => 'cleaning', 'what' => 'Water tank cleaned', 'amount' => 95_000, 'method' => 'cash', 'reversed' => 'Entered twice'],
        ['when' => ['ago' => [2, 11]], 'property' => 'kisaasi', 'category' => 'Cleaning', 'vendor' => 'cleaning', 'what' => 'Water tank cleaned', 'amount' => 95_000, 'method' => 'cash'],
    ],

    // Papers kept on the record: a small generated PDF each (marked as a sample). [where, whose, kind, name].
    'documents' => [
        ['kisaasi:A2', 'tenant', 'id', 'National ID copy (sample)'],
        ['kisaasi:A2', 'lease', 'agreement', 'Signed tenancy agreement (sample)'],
        ['kyanja:H6', 'tenant', 'id', 'National ID copy (sample)'],
        ['kyanja:H6', 'lease', 'agreement', 'Signed tenancy agreement (sample)'],
        ['ntinda:S1', 'tenant', 'other', 'Company registration certificate (sample)'],
        ['bukoto:V1', 'lease', 'agreement', 'Signed tenancy agreement (sample)'],
    ],

    // The words of the collections log. {date} is the promise day.
    'notes' => [
        'promise' => ['Called: says salary comes in by {date} and will clear it then.', 'Spoke on WhatsApp: will send by mobile money by {date}.', 'Visited the unit: promised to pay by {date}.',
            'Called: waiting for a client to pay, promised {date}.'],
        'broken' => ['Did not pay on {date} as promised. Phone off this morning.', 'The promise of {date} was not kept. Reached in the evening: asks for one more week.',
            'Nothing came on {date}. Left a message at the unit.'],
        'former' => ['Called about the balance left at move-out: says something will come at month end.', 'Number not answering. Sent a note on WhatsApp with the balance.',
            'Met in town: agreed to clear the balance in small parts.'],
        'reminder' => ['Reminder sent on WhatsApp.', 'SMS reminder sent.', 'Reminded at the gate this morning.'],
        'today' => 'Called this morning: says the money comes today.',
    ],

    /*
    | A landlord on his own with no agency: a second, small demo account in owner mode, with its own owner sign-in. Same
    | shape as above; sections left out are empty. Its address is <key>@{solo.domain}, with the same password.
    */
    'solo' => [
        'domain' => 'mukasarentals.example',
        'agency' => [
            'name' => 'Mukasa Rentals (Demo)',
            'address' => 'Plot 00, Bweyogerere, Wakiso (fictional)',
            'phone' => '+256 700 000 900',
            'email' => 'office',
            'plan' => 'estate-ledger-starter', // paid: tenants' own sign-in comes with a paid plan
            'phones' => ['tenant' => 256_700_000_910, 'kin' => 256_700_000_950],
            'names_from' => 150, // people other than the agency's
            'settings' => ['mode' => 'owner', 'currency' => 'UGX', 'bill_days_before' => 5, 'grace_days' => 5, 'anchor' => 'start', 'prorate_first' => true,
                'deposit_months' => 1, 'late_fee_type' => 'none',
                'payment_instructions' => "Pay rent to Godfrey Mukasa:\n- MTN MoMo 0700 000 900 (fictional)\nWrite your room number as the reference.",
                'receipt_footer' => 'Mukasa Rentals (Demo): a sample account. Every name and number in it is made up.'],
        ],
        'logins' => [
            'owner' => ['name' => 'Godfrey Mukasa', 'role' => 'owner', 'label' => 'A landlord on his own, with no agency'],
        ],
        'properties' => [
            'bweyogerere' => ['name' => 'Mukasa Courtyard', 'kind' => 'mixed', 'area' => 'Bweyogerere', 'district' => 'Wakiso', 'address' => 'Bweyogerere Road (fictional plot)',
                'water_rate' => 5_000, 'meters' => ['water'], 'units' => array_merge(
                    array_map(fn ($n) => ["R{$n}", 'single_room', 180_000, 1, 1], range(1, 8)),
                    [['F1', 'self_contained', 350_000, 1, 1], ['F2', 'self_contained', 350_000, 1, 1], ['S1', 'shop', 300_000, 1, null], ['S2', 'shop', 300_000, 1, null]])],
        ],
        'empty_on_day_one' => ['bweyogerere:R8'],
        'tenancies' => [
            'bweyogerere:R3' => ['since' => 8, 'day' => 1, 'pay' => 'behind:1', 'm' => 'cash', 'owed' => 180_000],
            'bweyogerere:R5' => ['since' => 14, 'day' => 10, 'pay' => 'behind:2', 'm' => 'momo'],
            'bweyogerere:F1' => ['since' => 20, 'day' => 1, 'pay' => 'ahead', 'm' => 'momo', 'credit' => 700_000],
            'bweyogerere:S2' => ['since' => 12, 'day' => 15, 'pay' => 'parts', 'm' => 'momo'],
        ],
        'opening_cash' => ['cash' => 600_000, 'mobile_money' => 900_000, 'bank' => 2_500_000],
        'landlord_work' => ['close_months' => 3],
        'move_outs' => [
            'bweyogerere:R6' => ['ago' => [4, 15], 'refund_deposit' => 'cash', 'water' => 2.6, 'damages' => [['Window pane replaced', 40_000]], 'reason' => 'Moved back to Mbale'],
        ],
        'one_expenses' => [
            ['when' => ['ago' => [6, 4]], 'property' => 'bweyogerere', 'category' => 'Property rates', 'what' => 'Rates for the year', 'amount' => 250_000, 'method' => 'bank'],
        ],
        'monthly_expenses' => [
            ['day' => 3, 'property' => 'bweyogerere', 'category' => 'Security', 'what' => 'Night guard', 'amount' => 150_000, 'method' => 'mobile_money'],
            ['day' => 8, 'property' => 'bweyogerere', 'category' => 'Water', 'what' => 'Bulk water bill', 'amount' => 70_000, 'method' => 'mobile_money'],
        ],
        'maintenance' => [
            ['bweyogerere:R2', 'Door hinge broken', 'normal', 'tenant', ['ago' => [5, 10]], ['done' => 2, 'cost' => 25_000, 'by' => 'Musa the carpenter (demo)']],
            ['bweyogerere', 'Water tank overflowing', 'high', 'staff', ['days_ago' => 3], []],
        ],
    ],
];

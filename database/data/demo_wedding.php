<?php

/*
| The public demo's pledge books (App\Services\Onboarding\PublicDemoWedding): Brian Tumusiime and Grace Nakato's
| Kukyala (done and closed), their Kwanjula (in five weeks) and their church wedding (in eleven weeks), in Uganda
| shillings. Days are counted from the day the demo is built, so the countdowns always lie ahead.
|
| Each event: its budget template (resources/data/budget_templates.json) and guest count, its settings (venue,
| message language, presets, a committee meeting), the share of each budget item already paid out (items not
| listed: nothing yet), and its pledgers by group (how many, the amounts they choose from, and what the first named
| person of the group pledges, the parents for a family). Names are made from
| the pools below, the same ones on every build; phones are made-up Ugandan mobile numbers (DemoPledgeExtras::phoneFor).
*/
return [
    'couple' => ['groom' => 'Brian Tumusiime', 'bride' => 'Grace Nakato'],

    'events' => [
        'kukyala' => [
            'name' => 'Brian & Grace Kukyala', 'template' => 'kukyala-visitation', 'guests' => 30, 'day' => -42, 'open' => false,
            'venue' => 'The Nakato home, Kisaasi', 'message_lang' => 'lg', 'recorded_from' => 70, 'recorded_to' => 44,
            'bottom' => 'Webale nnyo olw\'okutuwagira.',
            'spent' => ['*' => 1.0], // all paid out: the visit is over
            'status' => ['paid' => 100, 'part' => 0, 'none' => 0],
            'groups' => [
                ["Groom's family", 8, [50000, 100000, 100000, 200000], 300000],
                ['Friends', 5, [50000, 50000, 100000]],
                ['Others', 2, [20000, 50000]],
            ],
        ],
        'kwanjula' => [
            'name' => 'Brian & Grace Kwanjula', 'template' => 'kwanjula-buganda', 'guests' => 300, 'day' => 35, 'open' => true,
            'venue' => 'The Nakato home, Kisaasi', 'message_lang' => 'lg', 'recorded_from' => 60, 'recorded_to' => 1,
            'bottom' => 'Webale nnyo olw\'okutuwagira. Tukwaniriza mu Kwanjula lwa Brian ne Grace.',
            'meeting' => ['when' => 'Sundays at 4:00 pm', 'where' => 'The Tumusiime home, Ntinda'],
            'spent' => [
                'Omutwalo (bride price envelope)' => 0.0, "Kanzu for the bride's father" => 1.0, "Coat (jacket) for the bride's father" => 1.0,
                "Gomesi for the bride's mother" => 1.0, 'Gomesi for the ssenga' => 1.0, 'Gomesi for the aunts' => 0.5, 'Kanzu and coat for the groom' => 1.0,
                'Matching kanzus for the delegation' => 0.6, 'Decor: stage, drapes and flowers' => 0.3, 'Omwogezi (spokesman)' => 0.5,
                'Photo and video' => 0.4, 'Tents (100 seats each)' => 0.5, 'Coaster bus for the delegation' => 0.5,
            ],
            'status' => ['paid' => 40, 'part' => 32, 'none' => 28],
            'groups' => [
                ["Groom's family", 14, [200000, 300000, 300000, 500000, 500000, 1000000], 2000000],
                ['Friends', 12, [50000, 100000, 100000, 150000, 200000, 300000]],
                ['Workmates', 8, [50000, 100000, 100000, 200000]],
                ['Old school mates', 6, [50000, 100000, 150000]],
                ['Committee', 5, [200000, 300000, 500000]],
                ['Others', 4, [20000, 50000, 100000]],
            ],
        ],
        'wedding' => [
            'name' => 'Brian & Grace Wedding', 'template' => 'anglican-wedding', 'guests' => 300, 'day' => 77, 'open' => true, 'default' => true,
            'venue' => "St. Paul's Cathedral Namirembe, reception at Mukono Resort gardens", 'message_lang' => 'en', 'recorded_from' => 75, 'recorded_to' => 0,
            'bottom' => 'Thank you for standing with Brian and Grace.',
            'meeting' => ['when' => 'Thursdays at 6:30 pm', 'where' => 'Mukono Resort, conference room'],
            'reminder' => "Hello {name}! {event} is in {days_left} days ({date}).\nYou pledged {amount}, paid {paid}; {balance} to go.\nMTN MoMo: {payment_number}\nTreasurer: {treasurer} {treasurer_phone}\nThank you for standing with Brian and Grace!",
            'spent' => [
                'Church wedding fees (booking, service, certificate)' => 1.0, 'Pre-marital counselling' => 1.0, 'Garden or hall hire' => 0.5,
                'Decor package' => 0.4, 'Wedding gown' => 1.1, "Groom's suit" => 1.0, 'Wedding rings' => 1.0, 'Photo and video' => 0.5, 'MC' => 0.5,
                'DJ' => 0.3, 'Bridal car (with driver and fuel)' => 0.3, 'Invitation cards' => 1.0,
            ],
            'status' => ['paid' => 42, 'part' => 30, 'none' => 28],
            'groups' => [
                ["Groom's family", 22, [100000, 200000, 200000, 300000, 300000, 500000, 500000, 1000000], 3000000],
                ["Bride's family", 20, [100000, 200000, 200000, 300000, 500000, 500000, 1000000], 2000000],
                ['Friends', 24, [50000, 50000, 100000, 100000, 150000, 200000, 200000, 300000], 500000],
                ['Workmates', 14, [50000, 100000, 100000, 150000, 200000], 1000000],
                ['Church', 12, [20000, 30000, 50000, 50000, 100000, 100000, 200000], 300000],
                ['Old school mates', 10, [50000, 100000, 100000, 150000], 600000],
                ['Committee', 6, [200000, 300000, 500000, 500000]],
                ['Others', 6, [20000, 50000, 50000, 100000]],
            ],
        ],
    ],

    // A few named pledgers first in their groups (the rest come from the pools), and groups that pledge together.
    'named' => [
        "Groom's family" => ['Mr. & Mrs. Tumusiime Fred', 'Aunt Kyomuhendo Annet', 'Uncle Mugisha Patrick', 'Kakuru Edgar', 'Ainembabazi Brenda', 'Uncle Byaruhanga Simon'],
        "Bride's family" => ['Mr. & Mrs. Nakato Ssemwanga Joseph', 'Ssenga Nabirye Florence', 'Hajji Ssali Abdul', 'Nalongo Nakimuli Sarah', 'Ssempala Ivan'],
        'Friends' => ['Okello James', 'Nansubuga Prossy', 'Achieng Mercy'],
        'Workmates' => ["Grace's office (finance team)", 'Kiwanuka Peter', 'Nakalema Faith'],
        'Church' => ['Namirembe choir', "Mothers' Union, St. Paul's", 'Rev. Canon Mukiibi Stephen'],
        'Old school mates' => ['Class of 2012 (old students)', 'Lwanga Emmanuel'],
        'Committee' => ['Kasozi Henry', 'Nambi Christine'],
    ],

    'surnames' => ['Mugisha', 'Byaruhanga', 'Tumwine', 'Atuhaire', 'Ainomugisha', 'Kyomugisha', 'Twinomujuni', 'Asiimwe', 'Kamugisha', 'Ahimbisibwe',
        'Ssemakula', 'Nakato', 'Mukasa', 'Nansubuga', 'Kizza', 'Ssebunya', 'Namubiru', 'Lubega', 'Nakimuli', 'Ssali', 'Kiggundu', 'Nalwoga', 'Mutebi',
        'Okello', 'Akello', 'Opio', 'Auma', 'Ochieng', 'Atim', 'Odongo', 'Apio', 'Wasswa', 'Babirye', 'Kato', 'Nakayima', 'Mwesigwa', 'Kobusingye',
        'Tugume', 'Natukunda', 'Bbosa', 'Nantongo', 'Kawooya', 'Bukenya', 'Nanteza', 'Mugerwa', 'Kyambadde', 'Ssenyonga', 'Kabuye', 'Namirembe',
        'Wamala', 'Musoke', 'Ouma', 'Acen', 'Ekwang', 'Amuge', 'Mbabazi', 'Arinaitwe', 'Tusiime', 'Kansiime', 'Turyahabwe'],
    'first_names' => ['Ronald', 'Allan', 'Joyce', 'Samuel', 'Sharon', 'Paul', 'Agnes', 'Dennis', 'Gloria', 'Isaac', 'Arnold', 'Patricia', 'Julius',
        'Winnie', 'Ruth', 'Diana', 'Tonny', 'Andrew', 'Lillian', 'Moses', 'Esther', 'Collins', 'Juliet', 'Derrick', 'Rebecca', 'Godfrey', 'Hassan',
        'Rose', 'Fred', 'Brenda', 'Edgar', 'Martin', 'Faith', 'Peter', 'Joan', 'Robert', 'Harriet', 'Emmanuel', 'Doreen', 'Ivan', 'Sylvia', 'Simon',
        'Mercy', 'Denis', 'Prossy', 'Jonathan', 'Annet', 'Hilda', 'Josephine', 'Richard', 'Stella', 'Herbert', 'Phiona', 'Eric', 'Racheal', 'Charles'],
    'titles' => ['', '', '', '', '', '', 'Dr. ', 'Eng. ', 'Pastor '],
];

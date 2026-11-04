<?php

/*
| Kuku Tally's own demo account (App\Services\Onboarding\AppDemoBuilder): who runs the farm. The farm itself, Layers House 1
| and the broilers sold earlier, is FamilyDemoBuilder's plan. Every person and number is made up.
*/
return [
    'company' => [
        'business_type' => 'poultry',
        'phone' => '+256 752 555 120',
        'email' => 'farm@nsubugapoultry.example',
        'domain' => 'nsubugapoultry.example',
    ],

    'owner' => 'Joseph Nsubuga',

    // The first one writes the farm's records down.
    'team' => [
        ['Prossy Namuli', 'manager'],
        ['Emmanuel Wasswa', 'stock_keeper'],
        ['Harriet Babirye', 'accountant'],
    ],

    // One button per role on the /demo page (PublicDemo::roles).
    'roles' => [
        'owner' => ['label' => 'Farm owner', 'what' => 'Sees the birds, the eggs, the feed, and the sales and costs.'],
        'manager' => ['label' => 'Farm manager', 'what' => 'Keeps the daily card and the flock\'s health.'],
        'stock_keeper' => ['label' => 'Feed store keeper', 'what' => 'Keeps the feed going in and out. No sales or profit.'],
        'accountant' => ['label' => 'Bookkeeper', 'what' => 'Reads the reports and keeps the money records.'],
    ],
];

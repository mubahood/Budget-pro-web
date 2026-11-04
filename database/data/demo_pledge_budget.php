<?php

/*
| Pledge & Budget's own demo account (App\Services\Onboarding\AppDemoBuilder): the committee. The events, Brian and Grace's
| Kukyala, Kwanjula and wedding, are in database/data/demo_wedding.php. Every person and number is made up.
*/
return [
    'company' => [
        'business_type' => 'fundraising',
        'phone' => '+256 774 555 130',
        'email' => 'committee@brianandgrace.example',
        'domain' => 'brianandgrace.example',
    ],

    'owner' => 'Brian Tumusiime',

    // The first one keeps the money.
    'team' => [
        ['Doreen Natukunda', 'accountant'],
        ['Allan Kyeyune', 'manager'],
        ['Winnie Nalwoga', 'viewer'],
    ],

    // Each on the events' committee, by first name in lower case.
    'committee' => ['allan' => 'secretary', 'winnie' => 'viewer'],

    // One button per role on the /demo page (PublicDemo::roles).
    'roles' => [
        'owner' => ['label' => 'Chair', 'what' => 'Runs the events and their committee, and sees everything.'],
        'accountant' => ['label' => 'Treasurer', 'what' => 'Records the payments and pays the budget items.'],
        'manager' => ['label' => 'Secretary', 'what' => 'Writes down the pledges and keeps the lists.'],
        'viewer' => ['label' => 'Committee member', 'what' => 'Looks at the figures and changes nothing.'],
    ],
];

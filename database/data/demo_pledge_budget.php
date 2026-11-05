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

    // The first one keeps the money. On the account everyone but the chair is a viewer: their seat on the event is what gives them
    // anything more, so each role's limits are the real ones (PB-30, decision D3). The account's owner chairs (PublicDemo::rebuild).
    'team' => [
        ['Doreen Natukunda', 'viewer'],
        ['Allan Kyeyune', 'viewer'],
        ['Moses Kawooya', 'viewer'],
        ['Winnie Nalwoga', 'viewer'],
    ],

    // Each on the events' committee, by first name in lower case.
    'committee' => ['allan' => 'secretary', 'moses' => 'member', 'winnie' => 'viewer'],

    // One button per role on the /demo page (PublicDemo::roles), each naming the person it opens.
    'roles' => [
        'owner' => ['label' => 'Chair', 'what' => 'Runs the event and its committee, and sees everything.'],
        'treasurer' => ['label' => 'Treasurer', 'what' => 'Records payments and what was paid out.', 'person' => 'Doreen Natukunda'],
        'secretary' => ['label' => 'Secretary', 'what' => 'Writes down pledges. Records no money.', 'person' => 'Allan Kyeyune'],
        'member' => ['label' => 'Committee member', 'what' => 'Writes down pledges too. Records no money.', 'person' => 'Moses Kawooya'],
        'viewer' => ['label' => 'Viewer', 'what' => 'Sees names and amounts, no phone numbers. Changes nothing.', 'person' => 'Winnie Nalwoga'],
    ],

    // The story on top of the books (DemoPledgeExtras::story). Every name is made up.
    'story' => [
        'withdrawn_reason' => 'Asked to take it off: moved abroad and cannot pay.',
        'in_kind' => ['name' => 'Mzee Rwakatungu John', 'group' => "Groom's family", 'what' => 'Two goats for the reception', 'value' => 800000],
        'closing' => ['held_by' => 'Doreen Natukunda', 'note' => 'What was left moves to the kwanjula\'s book.'],
        'double_reference' => 'MTN MoMo 7790011223',
    ],
];

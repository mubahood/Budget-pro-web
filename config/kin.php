<?php

/*
| My Family Roots 360 (the kin module): plans, the family purse switch and the demo. No secrets, no real families.
*/
return [
    // The plan line's trial and free plans (FamilyRootsPlanSeeder, PlanLine::FAMILY_ROOTS).
    'plans' => ['trial' => 'family-roots-trial', 'free' => 'family-roots-free'],

    // A family's purse (dues, welfare) runs on the SACCO books beside the tree: ['kin', 'sacco'] is allowed only while
    // this is on (CompanyRules::exclusiveOk). Off until the purse is built (docs 05/11).
    'purse_enabled' => (bool) env('KIN_PURSE_ENABLED', false),

    // The brand Family Roots speaks with in emails and as the sender name.
    'brand' => [
        'name' => 'My Family Roots 360',
        'short_name' => 'Family Roots',
        'primary' => '#2F5D3A',
        'accent' => '#8A5A2B',
        'background' => '#FAF7F0',
    ],

    // What a person's record may say (sacco_members, docs 05 §5.2).
    'statuses' => ['active', 'applicant', 'deceased', 'exited'],
    'birth_date_precisions' => ['exact', 'year', 'about'],
    'dues_duties' => ['compulsory', 'optional', 'exempt'],
    // kin_links: a parent is a father, a mother or a parent (unsaid, e.g. a guardian); at most one birth father and one birth
    // mother each, any number of adoptive, step and guardian parents (TreeService).
    'link_roles' => ['father', 'mother', 'parent'],
    'link_kinds' => ['birth', 'adoptive', 'step', 'guardian'],
    'spouse_statuses' => ['current', 'ended', 'widowed'],

    // The demo family (App\Services\Kin\Demo\KinDemo, `kin:demo`): invented people only, one sign-in per role on the
    // demo's own made-up domain, sharing one published demo password (like the other apps' demos). SaaS only.
    'demo' => [
        'enabled' => (bool) env('DEMO_KIN', true),
        'company_name' => 'The Sample Family (Demo)',
        'domain' => env('DEMO_KIN_DOMAIN', 'samplefamily.example'),
        'password' => env('DEMO_KIN_PASSWORD', 'demo-family-roots-2026'),
        'plan' => 'family-roots-clan',
        'rebuild_hours' => (int) env('DEMO_KIN_REBUILD_HOURS', 72),
    ],
];

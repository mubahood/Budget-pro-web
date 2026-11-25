<?php

/*
| UnityDesk (the unity module): plans, brand, membership words and the demo. No secrets, no real associations.
*/
return [
    // The plan line's trial and free plans (UnityDeskPlanSeeder, PlanLine::UNITYDESK).
    'plans' => ['trial' => 'unitydesk-trial', 'free' => 'unitydesk-free'],

    // The brand UnityDesk speaks with in emails and as the sender name. It names no other app, only "by Solavia".
    'brand' => [
        'name' => 'UnityDesk',
        'short_name' => 'UnityDesk',
        'primary' => '#2B3A67',
        'accent' => '#1F8A70',
        'background' => '#F7F8FA',
    ],

    // A member's standing, worked out by App\Services\Unity\Memberships from their subscriptions (cached on sacco_members).
    'standings' => ['good', 'lapsed', 'life'],
    'subscription_statuses' => ['pending', 'paid', 'waived', 'cancelled'],
    'payment_methods' => ['cash', 'mobile_money', 'bank', 'cheque', 'other'],

    // The demo association (App\Services\Unity\Demo\UnityDemo, `unity:demo`): invented members only, one sign-in per role
    // on the demo's own made-up domain, sharing one published demo password (public by design). SaaS only.
    'demo' => [
        'enabled' => (bool) env('DEMO_UNITY', true),
        'company_name' => 'Sample Association (Demo)',
        'domain' => env('DEMO_UNITY_DOMAIN', 'sampleassociation.example'),
        'password' => env('DEMO_UNITY_PASSWORD', 'demo-unitydesk-2026'),
        'plan' => 'unitydesk-organisation',
        'rebuild_hours' => (int) env('DEMO_UNITY_REBUILD_HOURS', 72),
    ],
];

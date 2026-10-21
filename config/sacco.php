<?php

/*
| Akiba Ledger, the `sacco` module (docs/akiba/04-architecture.md §17). Values come from the environment; no secrets
| in git. Each SACCO's own choices live in sacco_settings; these are the platform's defaults and switches.
*/
return [
    // The plan line's trial and free fallback (PlanLine, AkibaPlanSeeder; AK-15, AK-16).
    'plans' => ['trial' => env('SACCO_TRIAL_PLAN', 'akiba-trial'), 'free' => env('SACCO_FREE_PLAN', 'akiba-free')],
];

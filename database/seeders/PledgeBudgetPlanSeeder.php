<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Billing\PlanLine;
use Illuminate\Database\Seeder;

/**
 * Pledge & Budget's trial (the review's PB-33, decision D11): the family's trial with 10 logins, so a chair can try it with the whole
 * committee. Not public, on the family's line, free, and no other limit or price changes. Idempotent by slug: an edited plan is kept.
 */
class PledgeBudgetPlanSeeder extends Seeder
{
    public const MAX_USERS = 10;

    public function run(): void
    {
        if (Plan::where('slug', PlanLine::EVENTS_TRIAL)->exists()) {
            return;
        }
        $family = Plan::where('slug', config('saas.default_plan', 'trial'))->first();
        Plan::create([
            'name' => 'Pledge & Budget trial', 'slug' => PlanLine::EVENTS_TRIAL, 'description' => 'The trial for a committee: every feature, with room for 10 logins.',
            'price' => 0, 'price_ugx' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => (int) ($family->trial_days ?? config('saas.trial_days', 14)), 'is_public' => false, 'sort_order' => (int) ($family->sort_order ?? 0),
            'features' => (array) ($family->features ?? []),
            'limits' => ['max_users' => self::MAX_USERS] + (array) ($family->limits ?? []),
        ]);
    }
}

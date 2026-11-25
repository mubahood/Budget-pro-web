<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Billing\PlanLine;
use Illuminate\Database\Seeder;

/**
 * My Family Roots 360's own plans: the `family-roots` line, counted in people on the tree (Quotas `people`: everyone
 * active, joining or departed, not those who left). Proposals the platform admin may edit (prices are the owner's call):
 * like EstateLedgerPlanSeeder this is idempotent by slug, creating missing plans and adding missing limit and feature
 * keys only, so a deploy never overwrites an edited price. No setup fee; a year costs 10 months (Plan::ANNUAL_MONTHS).
 *
 *   Free    up to 150 people
 *   Family  up to 1,000 people   UGX 20,000 a month
 *   Clan    no limit on people   UGX 50,000 a month
 */
class FamilyRootsPlanSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public static function plans(): array
    {
        $line = ['line' => PlanLine::FAMILY_ROOTS, 'whatsapp_receipts' => true];

        return [
            ['name' => 'Family Roots trial', 'slug' => 'family-roots-trial', 'description' => '30 days of My Family Roots 360, free, while you bring your family in.',
                'price' => 0, 'price_ugx' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => false, 'sort_order' => 300,
                'features' => $line,
                'limits' => ['max_people' => 1000, 'max_users' => 10, 'max_devices' => 10, 'storage_mb' => 500]],
            ['name' => 'Family Roots Free', 'slug' => 'family-roots-free', 'description' => 'For a household: up to 150 people on the family tree.',
                'price' => 0, 'price_ugx' => 0, 'price_ugx_annual' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => true, 'sort_order' => 301,
                'features' => $line,
                'limits' => ['max_people' => 150, 'max_users' => 3, 'max_devices' => 3, 'storage_mb' => 100]],
            ['name' => 'Family Roots Family', 'slug' => 'family-roots-family', 'description' => 'For an extended family: up to 1,000 people, branches and officers.',
                'price' => 6, 'price_ugx' => 20000, 'price_ugx_annual' => 200000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 302,
                'features' => $line,
                'limits' => ['max_people' => 1000, 'max_users' => 10, 'max_devices' => 10, 'storage_mb' => 1000]],
            ['name' => 'Family Roots Clan', 'slug' => 'family-roots-clan', 'description' => 'For a whole clan: no limit on people, and more officers.',
                'price' => 14, 'price_ugx' => 50000, 'price_ugx_annual' => 500000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 303,
                'features' => $line,
                'limits' => ['max_people' => null, 'max_users' => 30, 'max_devices' => 30, 'storage_mb' => 5000]],
        ];
    }

    public function run(): void
    {
        foreach (self::plans() as $data) {
            $plan = Plan::where('slug', $data['slug'])->first();
            if ($plan === null) {
                Plan::create($data);

                continue;
            }
            $plan->limits = (array) ($plan->limits ?? []) + $data['limits'];
            $plan->features = (array) ($plan->features ?? []) + $data['features'];
            if ($plan->isDirty(['limits', 'features'])) {
                $plan->save();
            }
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Billing\PlanLine;
use Illuminate\Database\Seeder;

/**
 * UnityDesk's own plans: the `unitydesk` line, counted in members (Quotas `unity_members`: active, joining, dormant
 * and suspended members, not those who left). Idempotent by slug like FamilyRootsPlanSeeder: creates missing plans and
 * adds missing limit and feature keys only, so a deploy never overwrites an edited price. A year costs 10 months.
 *
 *   Free          up to 100 members
 *   Group         up to 1,000 members   UGX 25,000 a month (250,000 a year)
 *   Organisation  no limit on members   UGX 60,000 a month (600,000 a year)
 */
class UnityDeskPlanSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public static function plans(): array
    {
        $line = ['line' => PlanLine::UNITYDESK, 'whatsapp_receipts' => true];

        return [
            ['name' => 'UnityDesk trial', 'slug' => 'unitydesk-trial', 'description' => '30 days of UnityDesk, free, while you bring your members in.',
                'price' => 0, 'price_ugx' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => false, 'sort_order' => 400,
                'features' => $line,
                'limits' => ['max_unity_members' => 1000, 'max_users' => 10, 'max_devices' => 10, 'storage_mb' => 2000]],
            ['name' => 'UnityDesk Free', 'slug' => 'unitydesk-free', 'description' => 'For a small club: up to 100 members.',
                'price' => 0, 'price_ugx' => 0, 'price_ugx_annual' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => true, 'sort_order' => 401,
                'features' => $line,
                'limits' => ['max_unity_members' => 100, 'max_users' => 3, 'max_devices' => 3, 'storage_mb' => 300]],
            ['name' => 'UnityDesk Group', 'slug' => 'unitydesk-group', 'description' => 'For an association: up to 1,000 members, chapters, officers and subscriptions.',
                'price' => 7, 'price_ugx' => 25000, 'price_ugx_annual' => 250000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 402,
                'features' => $line,
                'limits' => ['max_unity_members' => 1000, 'max_users' => 10, 'max_devices' => 10, 'storage_mb' => 2000]],
            ['name' => 'UnityDesk Organisation', 'slug' => 'unitydesk-organisation', 'description' => 'For a large organisation: no limit on members, more officers and chapters.',
                'price' => 16, 'price_ugx' => 60000, 'price_ugx_annual' => 600000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 403,
                'features' => $line,
                'limits' => ['max_unity_members' => null, 'max_users' => 30, 'max_devices' => 30, 'storage_mb' => 10000]],
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

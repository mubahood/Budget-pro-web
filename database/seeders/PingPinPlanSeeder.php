<?php

namespace Database\Seeders;

use App\Models\PingPinPlan;
use Illuminate\Database\Seeder;

/**
 * Three dynamic, admin-editable packages plus a 14-day trial (brief §4).
 * Concrete, real values — not placeholders — since these need to be
 * genuinely demo-able immediately (TASKS.md 2.1's acceptance criteria).
 * UGX pricing set at roughly the USD price converted at ~3,800 UGX/USD and
 * rounded to a clean number, matching budget-pro's PlanSeeder convention.
 */
class PingPinPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Trial',
                'slug' => 'trial',
                'description' => '14 days, full location tracking on one device.',
                'price' => 0,
                'price_ugx' => 0,
                'interval' => 'month',
                'trial_days' => 14,
                'is_public' => false, // not purchasable — assigned automatically at signup
                'sort_order' => 0,
                'features' => [
                    'live_location' => true,
                    'geofencing' => true,
                    'remote_ring' => true,
                    'remote_lock' => false,
                    'remote_wipe' => false,
                    'sms_fallback' => false,
                    'intruder_photo' => false,
                    'police_report' => false,
                    'web_dashboard' => true,
                ],
                'limits' => [
                    'max_devices' => 1,
                    'max_geofences' => 1,
                    'history_retention_days' => 7,
                    'max_trusted_contacts' => 1,
                ],
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'One device, fully protected.',
                'price' => 2.99,
                'price_ugx' => 11000,
                'interval' => 'month',
                'trial_days' => 0,
                'is_public' => true,
                'sort_order' => 1,
                'features' => [
                    'live_location' => true,
                    'geofencing' => true,
                    'remote_ring' => true,
                    'remote_lock' => true,
                    'remote_wipe' => true,
                    'sms_fallback' => true,
                    'intruder_photo' => false,
                    'police_report' => false,
                    'web_dashboard' => true,
                ],
                'limits' => [
                    'max_devices' => 1,
                    'max_geofences' => 3,
                    'history_retention_days' => 30,
                    'max_trusted_contacts' => 3,
                ],
            ],
            [
                'name' => 'Family',
                'slug' => 'family',
                'description' => 'Up to 5 devices — the whole family or a small team.',
                'price' => 6.99,
                'price_ugx' => 26000,
                'interval' => 'month',
                'trial_days' => 0,
                'is_public' => true,
                'sort_order' => 2,
                'features' => [
                    'live_location' => true,
                    'geofencing' => true,
                    'remote_ring' => true,
                    'remote_lock' => true,
                    'remote_wipe' => true,
                    'sms_fallback' => true,
                    'intruder_photo' => true,
                    'police_report' => true,
                    'web_dashboard' => true,
                ],
                'limits' => [
                    'max_devices' => 5,
                    'max_geofences' => 10,
                    'history_retention_days' => 90,
                    'max_trusted_contacts' => 10,
                ],
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Unlimited devices for company fleets, with full history retention.',
                'price' => 19.99,
                'price_ugx' => 74000,
                'interval' => 'month',
                'trial_days' => 0,
                'is_public' => true,
                'sort_order' => 3,
                'features' => [
                    'live_location' => true,
                    'geofencing' => true,
                    'remote_ring' => true,
                    'remote_lock' => true,
                    'remote_wipe' => true,
                    'sms_fallback' => true,
                    'intruder_photo' => true,
                    'police_report' => true,
                    'web_dashboard' => true,
                ],
                'limits' => [
                    'max_devices' => null, // unlimited
                    'max_geofences' => null,
                    'history_retention_days' => 365,
                    'max_trusted_contacts' => null,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            PingPinPlan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}

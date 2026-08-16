<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Seeder;

/**
 * Seeds the default SaaS plans and backfills a subscription for every existing
 * company (so pre-billing tenants keep working). Idempotent.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Trial',
                'slug' => 'trial',
                'description' => '14-day free trial with core features.',
                'price' => 0,
                'currency' => 'USD',
                'interval' => 'month',
                'trial_days' => 14,
                'is_public' => false,
                'sort_order' => 0,
                'features' => ['inventory' => true, 'sales' => true, 'finance' => true, 'budgets' => true, 'api_access' => true, 'forecasting' => false, 'auto_reorder' => false],
                'limits' => ['max_users' => 2, 'max_stock_items' => 100, 'max_sales_per_month' => 200, 'max_budget_programs' => 3],
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For small shops getting started.',
                'price' => 19,
                'price_ugx' => 70000,
                'currency' => 'USD',
                'interval' => 'month',
                'trial_days' => 14,
                'sort_order' => 1,
                'features' => ['inventory' => true, 'sales' => true, 'finance' => true, 'budgets' => true, 'api_access' => true, 'forecasting' => false, 'auto_reorder' => false],
                'limits' => ['max_users' => 3, 'max_stock_items' => 500, 'max_sales_per_month' => 2000, 'max_budget_programs' => 10],
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'For growing businesses that need forecasting and automation.',
                'price' => 49,
                'price_ugx' => 185000,
                'currency' => 'USD',
                'interval' => 'month',
                'trial_days' => 14,
                'sort_order' => 2,
                'features' => ['inventory' => true, 'sales' => true, 'finance' => true, 'budgets' => true, 'api_access' => true, 'forecasting' => true, 'auto_reorder' => true],
                'limits' => ['max_users' => 10, 'max_stock_items' => 5000, 'max_sales_per_month' => 20000, 'max_budget_programs' => 50],
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Unlimited usage and priority support.',
                'price' => 149,
                'price_ugx' => 560000,
                'currency' => 'USD',
                'interval' => 'month',
                'trial_days' => 0,
                'sort_order' => 3,
                'features' => ['inventory' => true, 'sales' => true, 'finance' => true, 'budgets' => true, 'api_access' => true, 'forecasting' => true, 'auto_reorder' => true],
                'limits' => ['max_users' => null, 'max_stock_items' => null, 'max_sales_per_month' => null, 'max_budget_programs' => null],
            ],
        ];

        foreach ($plans as $data) {
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }

        // Backfill a subscription for every company that lacks one.
        $business = Plan::where('slug', 'business')->first();

        Company::query()->each(function (Company $company) use ($business) {
            if (Subscription::where('company_id', $company->id)->exists()) {
                return;
            }

            $expires = $company->license_expire;
            $isActive = $expires === null || $expires->endOfDay()->isFuture();

            Subscription::create([
                'company_id' => $company->id,
                'plan_id' => $business?->id,
                'status' => $isActive ? 'active' : 'expired',
                'starts_at' => $company->created_at,
                'ends_at' => $expires,
                'provider' => 'manual',
                'meta' => ['backfilled' => true],
            ]);
        });
    }
}

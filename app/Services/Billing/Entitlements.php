<?php

namespace App\Services\Billing;

use App\Models\Company;

/**
 * The entitlement snapshot a device caches (plan A10/C8/P1-6): plan, access
 * state (active | grace | expired | inactive), limits and feature flags.
 */
class Entitlements
{
    public const DEFAULT_LIMITS = ['max_products' => null, 'max_users' => null, 'max_sales_per_month' => null, 'max_locations' => 1, 'storage_mb' => 500, 'max_devices' => null];

    public const DEFAULT_FEATURES = ['forecasting' => false, 'auto_reorder' => false, 'multi_location' => false, 'whatsapp_receipts' => true, 'api_access' => false, 'shop_v2' => true, 'whatsapp_automation' => true];

    public static function for(Company $company): array
    {
        $subscription = $company->subscription;
        $plan = $subscription?->plan;
        $endedAt = $company->accessEndedAt();

        return [
            'state' => $company->accessState(),
            'plan' => $plan ? ['id' => $plan->id, 'slug' => $plan->slug, 'name' => $plan->name, 'interval' => $plan->interval] : null,
            'subscription_status' => $subscription?->status,
            'ends_at' => $endedAt?->toIso8601String(),
            'grace_days' => (int) config('saas.grace_days', 7),
            'grace_until' => $endedAt?->copy()->addDays((int) config('saas.grace_days', 7))->toIso8601String(),
            'limits' => array_merge(self::DEFAULT_LIMITS, $plan?->limits ?? []),
            'usage' => (new Quotas())->usage($company),
            'features' => array_merge(self::DEFAULT_FEATURES, $plan?->features ?? []),
            'negative_stock_policy' => $company->negative_stock_policy ?? 'flag',
            'currency' => $company->currency,
            'server_time' => (int) round(microtime(true) * 1000),
        ];
    }
}

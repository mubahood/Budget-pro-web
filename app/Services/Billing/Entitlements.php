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

    public const DEFAULT_FEATURES = ['forecasting' => false, 'auto_reorder' => false, 'multi_location' => false, 'whatsapp_receipts' => true, 'api_access' => false, 'shop_v2' => true, 'whatsapp_automation' => true,
        // Akiba Ledger (AkibaPlanSeeder): only sacco code reads them, so the four apps are unaffected.
        'sacco_office_mode' => false, 'sacco_returns' => false, 'sacco_momo' => false, 'sacco_payouts' => false, 'sacco_checkoff' => false,
        'sacco_member_app' => false, 'sacco_sms_receipts' => false];

    public static function for(Company $company): array
    {
        $subscription = $company->subscription;
        $plan = $subscription?->plan;
        $endedAt = $company->accessEndedAt();

        return [
            // A standalone copy has a licence, not a subscription: the shop is always open (docs/STANDALONE_EDITION_PLAN.md §5.6, S3).
            'state' => \App\Support\Edition::isStandalone() ? 'active' : $company->accessState(),
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

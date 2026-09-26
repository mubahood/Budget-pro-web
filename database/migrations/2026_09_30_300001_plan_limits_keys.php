<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The trial/starter/business/enterprise plans stored their product limit as `max_stock_items`, but
 * Entitlements/Quotas enforce `max_products` — so those plans had no product limit at all, and
 * none of the paid plans granted the branches (multi_location) that TransferService tells users
 * come with Business. Rename the key (values kept) and fill in the limits/features the enforcement
 * code reads. Idempotent; only plans still carrying the old key or missing a key are touched.
 */
return new class extends Migration
{
    /** slug => [limits to add when missing, features to add when missing] */
    private const DEFAULTS = [
        'trial' => [['max_locations' => 1, 'max_devices' => 2, 'storage_mb' => 100], ['multi_location' => false, 'whatsapp_receipts' => true]],
        'starter' => [['max_locations' => 1, 'max_devices' => 3, 'storage_mb' => 500], ['multi_location' => false, 'whatsapp_receipts' => true]],
        'business' => [['max_locations' => 3, 'max_devices' => 10, 'storage_mb' => 2000], ['multi_location' => true, 'whatsapp_receipts' => true]],
        'enterprise' => [['max_locations' => null, 'max_devices' => null, 'storage_mb' => null], ['multi_location' => true, 'whatsapp_receipts' => true]],
    ];

    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'slug', 'limits', 'features']) as $plan) {
            $limits = json_decode((string) $plan->limits, true) ?: [];
            $features = json_decode((string) $plan->features, true) ?: [];
            $before = [$limits, $features];

            if (array_key_exists('max_stock_items', $limits)) {
                $limits['max_products'] ??= $limits['max_stock_items'];
                unset($limits['max_stock_items']);
            }
            [$addLimits, $addFeatures] = self::DEFAULTS[$plan->slug] ?? [[], []];
            $limits += $addLimits;
            $features += $addFeatures;

            if ([$limits, $features] !== $before) {
                DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits), 'features' => json_encode($features), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Renaming back would switch the product limits off again.
    }
};

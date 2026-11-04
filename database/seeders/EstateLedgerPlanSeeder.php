<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Billing\PlanLine;
use Illuminate\Database\Seeder;

/**
 * Estate Ledger's own plans (docs/estate-ledger/04-architecture.md §15, KR-20): the `estate-ledger` line, counted in rental units
 * (Quotas `units`: every unit that is not inactive). Proposals the platform admin may edit (prices are the owner's
 * call): like AkibaPlanSeeder this is idempotent by slug, creating missing plans and adding missing limit and
 * feature keys only, so a deploy (db:seed runs on every deploy) never overwrites an edited price. No setup fee; a
 * year costs 10 months (Plan::ANNUAL_MONTHS).
 */
class EstateLedgerPlanSeeder extends Seeder
{
    /** Every rentals_* flag off; a plan turns on what it sells (Entitlements::DEFAULT_FEATURES has them all false). */
    private const OFF = ['rentals_portal' => false, 'rentals_sms' => false];

    /** @return list<array<string, mixed>> */
    public static function plans(): array
    {
        $line = ['line' => PlanLine::ESTATE_LEDGER, 'whatsapp_receipts' => true];
        $paid = ['rentals_portal' => true]; // tenants' and landlords' own sign-in; texts stay free links (rentals_sms, KR-18)

        return [
            ['name' => 'Estate Ledger trial', 'slug' => 'estate-ledger-trial', 'description' => '30 days of Estate Ledger, free, while you bring your properties, tenants and landlords in.',
                'price' => 0, 'price_ugx' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => false, 'sort_order' => 200,
                'features' => $line + $paid + self::OFF,
                'limits' => ['max_units' => 300, 'max_users' => 10, 'max_devices' => 10, 'storage_mb' => 500]],
            ['name' => 'Estate Ledger Free', 'slug' => 'estate-ledger-free', 'description' => 'For a landlord with up to 10 units: rent, tenants and receipts shared on WhatsApp.',
                'price' => 0, 'price_ugx' => 0, 'price_ugx_annual' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => true, 'sort_order' => 201,
                'features' => $line + self::OFF,
                'limits' => ['max_units' => 10, 'max_users' => 2, 'max_devices' => 2, 'storage_mb' => 100]],
            ['name' => 'Estate Ledger Starter', 'slug' => 'estate-ledger-starter', 'description' => 'For a landlord or a small agency: up to 60 units, with tenants\' and landlords\' own sign-in.',
                'price' => 9, 'price_ugx' => 35000, 'price_ugx_annual' => 350000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 202,
                'features' => $line + $paid + self::OFF,
                'limits' => ['max_units' => 60, 'max_users' => 4, 'max_devices' => 4, 'storage_mb' => 500]],
            ['name' => 'Estate Ledger Growth', 'slug' => 'estate-ledger-growth', 'description' => 'For a growing agency: up to 250 units and 10 staff.',
                'price' => 26, 'price_ugx' => 95000, 'price_ugx_annual' => 950000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 203,
                'features' => $line + $paid + self::OFF,
                'limits' => ['max_units' => 250, 'max_users' => 10, 'max_devices' => 10, 'storage_mb' => 2000]],
            ['name' => 'Estate Ledger Agency', 'slug' => 'estate-ledger-agency', 'description' => 'For a large agency: up to 2,000 units and 30 staff.',
                'price' => 68, 'price_ugx' => 250000, 'price_ugx_annual' => 2500000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 204,
                'features' => $line + $paid + self::OFF,
                'limits' => ['max_units' => 2000, 'max_users' => 30, 'max_devices' => 30, 'storage_mb' => 5000]],
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

<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Billing\PlanLine;
use Illuminate\Database\Seeder;

/**
 * Akiba Ledger's own plans (docs/akiba/03-proposal.md §5.2, AK-15, AK-16, AK-38): the `akiba` line, priced per
 * SACCO with member and SMS allowances. Proposals the platform admin may edit: like PlanSeeder this only
 * creates missing plans and adds missing limit and feature keys, so a deploy (db:seed runs on every deploy)
 * never overwrites an edited price. No setup fee; a year costs 10 months (Plan::ANNUAL_MONTHS).
 */
class AkibaPlanSeeder extends Seeder
{
    /** Every sacco_* flag off; a plan turns on what it sells (Entitlements::DEFAULT_FEATURES has them all false). */
    private const OFF = ['sacco_office_mode' => false, 'sacco_returns' => false, 'sacco_momo' => false, 'sacco_payouts' => false,
        'sacco_checkoff' => false, 'sacco_member_app' => false, 'sacco_sms_receipts' => false];

    /** @return list<array<string, mixed>> */
    public static function plans(): array
    {
        $line = ['line' => PlanLine::AKIBA, 'whatsapp_receipts' => true];
        $starter = ['sacco_office_mode' => true, 'sacco_sms_receipts' => true, 'sacco_member_app' => true];
        $standard = $starter + ['sacco_momo' => true, 'sacco_checkoff' => true];
        $compliance = $standard + ['sacco_returns' => true, 'sacco_payouts' => true];

        return [
            ['name' => 'Akiba trial', 'slug' => 'akiba-trial', 'description' => '30 days of Akiba Ledger Standard, free, while your SACCO settles in.',
                'price' => 0, 'price_ugx' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => false, 'sort_order' => 100,
                'features' => $line + $standard + self::OFF,
                'limits' => ['max_members' => 300, 'max_users' => 5, 'max_devices' => 5, 'storage_mb' => 500, 'max_sms_per_month' => 100]],
            ['name' => 'Akiba Free', 'slug' => 'akiba-free', 'description' => 'For savings groups of up to 30 members: the group books, with receipts shared on WhatsApp.',
                'price' => 0, 'price_ugx' => 0, 'price_ugx_annual' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => true, 'sort_order' => 101,
                'features' => $line + self::OFF,
                'limits' => ['max_members' => 30, 'max_users' => 2, 'max_devices' => 2, 'storage_mb' => 100, 'max_sms_per_month' => 0]],
            ['name' => 'Akiba Starter', 'slug' => 'akiba-starter', 'description' => 'Office mode for a young SACCO: members, shares, savings, SMS receipts and the member app.',
                'price' => 14, 'price_ugx' => 50000, 'price_ugx_annual' => 500000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 102,
                'features' => $line + $starter + self::OFF,
                'limits' => ['max_members' => 150, 'max_users' => 4, 'max_devices' => 4, 'storage_mb' => 500, 'max_sms_per_month' => 300]],
            ['name' => 'Akiba Standard', 'slug' => 'akiba-standard', 'description' => 'Adds mobile-money collections and payroll check-off.',
                'price' => 27, 'price_ugx' => 100000, 'price_ugx_annual' => 1000000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 103,
                'features' => $line + $standard + self::OFF,
                'limits' => ['max_members' => 600, 'max_users' => 8, 'max_devices' => 8, 'storage_mb' => 2000, 'max_sms_per_month' => 1200]],
            ['name' => 'Akiba Compliance', 'slug' => 'akiba-compliance', 'description' => 'Adds the regulator\'s returns and mobile-money payouts, for licensed SACCOs.',
                'price' => 68, 'price_ugx' => 250000, 'price_ugx_annual' => 2500000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 30, 'is_public' => true, 'sort_order' => 104,
                'features' => $line + $compliance + self::OFF,
                'limits' => ['max_members' => 2500, 'max_users' => 20, 'max_devices' => 20, 'storage_mb' => 5000, 'max_sms_per_month' => 4000]],
            ['name' => 'Akiba Enterprise', 'slug' => 'akiba-enterprise', 'description' => 'Everything, with no member, user or phone limit.',
                'price' => 162, 'price_ugx' => 600000, 'price_ugx_annual' => 6000000, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => true, 'sort_order' => 105,
                'features' => $line + array_map(fn () => true, self::OFF),
                'limits' => ['max_members' => null, 'max_users' => null, 'max_devices' => null, 'storage_mb' => null, 'max_sms_per_month' => 12000]],
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

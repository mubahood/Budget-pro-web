<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\FinancialPeriod;
use Illuminate\Database\Seeder;

/**
 * Backfills a default active financial period for any company that lacks one.
 *
 * StockItem, StockRecord and FinancialRecord all require an active financial
 * period to be created at all (it's a required foreign key derived from the
 * company's active period). Web registration and the v1 API always create
 * one; the legacy mobile-API registration did not until this was fixed
 * (see app/Http/Controllers/ApiController.php@register) — so any company
 * that registered through the mobile app before that fix has been unable to
 * record stock or finance data ever since. This seeder is idempotent: it
 * only touches companies with zero active periods, safe to re-run anytime.
 */
class BackfillFinancialPeriodsSeeder extends Seeder
{
    public function run(): void
    {
        $fixed = 0;

        Company::all()->each(function (Company $company) use (&$fixed) {
            $hasActive = FinancialPeriod::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'Active')
                ->exists();

            if ($hasActive) {
                return;
            }

            $period = new FinancialPeriod();
            $period->company_id = $company->id;
            $period->name = 'FY '.now()->year;
            $period->start_date = now()->startOfYear();
            $period->end_date = now()->endOfYear();
            $period->status = 'Active';
            $period->description = 'Default financial year (backfilled)';
            $period->total_investment = 0;
            $period->total_sales = 0;
            $period->total_profit = 0;
            $period->total_expenses = 0;
            $period->saveQuietly();

            $fixed++;
        });

        $this->command?->info("Backfilled active financial period for {$fixed} company(ies).");
    }
}

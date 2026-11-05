<?php

namespace App\Services\Rentals\Jobs;

use App\Models\Company;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Landlords\Handover;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The nightly run of every Estate Ledger account (`rentals:daily`): apply a property handover whose day has come, raise the bills that come due within the account's
 * horizon, then late fees. Idempotent: run twice, or after a missed night, it makes each bill once (dedupe keys).
 */
final class RentalDaily
{
    public function __construct(private Billing $billing) {}

    /** @return array{bills: int, late_fees: int} */
    public function run(int $companyId, ?Carbon $today = null): array
    {
        $today ??= LocalDate::today($companyId);
        RentalLedger::assertOpen($companyId, $today->toDateString()); // only `--date` can ask this: a real today is never closed

        app(Handover::class)->applyDue($companyId, $today); // before billing: a property whose handover day has come bills its new landlord

        return ['bills' => $this->billing->raiseAll($companyId, $today), 'late_fees' => $this->billing->lateFees($companyId, $today)];
    }

    /** Every account with the rentals module (one at a time; a failure in one does not stop the others). @return array<int, array> */
    public function runAll(?Carbon $today = null): array
    {
        $out = [];
        foreach (self::companyIds() as $id) {
            try {
                $out[$id] = $this->run($id, $today);
            } catch (\Throwable $e) {
                report($e);
                $out[$id] = ['error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /** @return list<int> */
    public static function companyIds(): array
    {
        return array_map('intval', DB::table('rental_settings')->whereIn('company_id', Company::withoutGlobalScopes()->select('id'))->pluck('company_id')->all());
    }
}

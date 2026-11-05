<?php

namespace App\Services\Rentals\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Whose money the account's cash is, on a day: a sum of the journal, nothing stored. ADR 0010's invariant is why it always adds up:
 * cash = owed to landlords − owed back by landlords + deposits + rent paid ahead + the agency's own (income − expense + equity).
 * Deposits a landlord holds never touched the agency's cash, so they are in no figure here.
 */
final class MoneyPosition
{
    /**
     * @param  string  $asOf  Y-m-d; every entry up to the end of that business date
     * @return array{as_of: string, cash_minor: int, owed_to_landlords_minor: int, owed_back_by_landlords_minor: int, deposits_minor: int, credit_minor: int, agency_own_minor: int, held_for_others_minor: int}
     */
    public static function asOf(int $companyId, string $asOf): array
    {
        $byLandlord = DB::table('rental_entries')->where('company_id', $companyId)->where('occurred_on', '<=', $asOf)->groupBy('landlord_id')
            ->selectRaw('SUM(landlord_minor) AS landlord, SUM(cash_minor) AS cash, SUM(deposit_minor) AS deposit, SUM(credit_minor) AS credit, SUM(income_minor) AS income, SUM(expense_minor) AS expense, SUM(equity_minor) AS equity')
            ->get();
        $owed = 0;
        $owedBack = 0;
        foreach ($byLandlord as $row) {
            $balance = (int) $row->landlord;
            $owed += max(0, $balance);
            $owedBack += max(0, -$balance);
        }
        $sum = fn (string $column): int => (int) $byLandlord->sum(fn ($row) => (int) $row->{$column});
        $deposits = $sum('deposit');
        $credit = $sum('credit');

        return ['as_of' => $asOf, 'cash_minor' => $sum('cash'), 'owed_to_landlords_minor' => $owed, 'owed_back_by_landlords_minor' => $owedBack, 'deposits_minor' => $deposits, 'credit_minor' => $credit,
            'agency_own_minor' => $sum('income') - $sum('expense') + $sum('equity'), 'held_for_others_minor' => $owed - $owedBack + $deposits + $credit];
    }
}

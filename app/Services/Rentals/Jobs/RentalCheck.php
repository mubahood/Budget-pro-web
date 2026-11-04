<?php

namespace App\Services\Rentals\Jobs;

use App\Services\Rentals\Payments\Deposits;
use Illuminate\Support\Facades\DB;

/**
 * Proves an account's books (`rentals:check`, docs/estate-ledger/04-architecture.md §5.12): every journal row balances,
 * every cache equals what it caches, and nothing is half reversed. Read only. Returns the problems found (empty =
 * the books agree), each with the ids to look at.
 */
final class RentalCheck
{
    /** @return list<array{check: string, detail: string, ids: list<int>}> */
    public function run(int $companyId): array
    {
        $p = [];
        $add = function (string $check, string $detail, $ids) use (&$p) {
            $ids = array_map('intval', collect($ids)->take(50)->all());
            if ($ids !== []) {
                $p[] = ['check' => $check, 'detail' => $detail, 'ids' => $ids];
            }
        };

        // 1. Every posting balances (the rows posted together), and every row but a transfer's legs on its own.
        $add('unbalanced_postings', 'Postings where cash ≠ landlord + deposit + credit + income − expense + equity',
            DB::table('rental_entries')->where('company_id', $companyId)->groupBy('group_id')
                ->havingRaw('SUM(cash_minor) <> SUM(landlord_minor + deposit_minor + credit_minor + income_minor - expense_minor + equity_minor)')
                ->selectRaw('MIN(id) AS id')->pluck('id'));
        $add('unbalanced_rows', 'Rows (other than transfers) where cash ≠ landlord + deposit + credit + income − expense + equity',
            DB::table('rental_entries as e')->where('e.company_id', $companyId)->where('e.type', '!=', 'transfer')
                ->where(fn ($q) => $q->whereNull('e.reverses_id')->orWhereNotIn('e.reverses_id', DB::table('rental_entries')->where('company_id', $companyId)->where('type', 'transfer')->select('id')))
                ->whereRaw('e.cash_minor <> e.landlord_minor + e.deposit_minor + e.credit_minor + e.income_minor - e.expense_minor + e.equity_minor')->pluck('e.id'));

        // 2. A bill's paid cache is the sum of its allocations; never more than its amount; status follows.
        $add('charge_paid_cache', 'Bills whose paid amount differs from their allocations',
            DB::table('rental_charges as c')->where('c.company_id', $companyId)
                ->whereRaw('c.paid_minor <> COALESCE((SELECT SUM(a.amount_minor) FROM rental_allocations a WHERE a.charge_id = c.id), 0)')->pluck('c.id'));
        $add('charge_overpaid', 'Bills paid beyond their amount', DB::table('rental_charges')->where('company_id', $companyId)->whereColumn('paid_minor', '>', 'amount_minor')->pluck('id'));
        $add('charge_status', 'Bills whose status does not match what is paid', DB::table('rental_charges')->where('company_id', $companyId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'paid')->whereColumn('paid_minor', '<', 'amount_minor'))
                ->orWhere(fn ($q) => $q->where('status', 'open')->whereColumn('paid_minor', '>=', 'amount_minor'))
                ->orWhere(fn ($q) => $q->where('status', 'void')->where('paid_minor', '>', 0)))->pluck('id'));

        // 3. A payment's credit is its amount less what its allocations hold (zero when reversed).
        $add('payment_credit_cache', 'Payments whose credit differs from amount − allocations', DB::table('rental_payments as p')->where('p.company_id', $companyId)
            ->whereNotIn('p.method', ['waiver', 'deposit'])
            ->whereRaw('p.unallocated_minor <> CASE WHEN p.reversed_at IS NULL THEN p.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM rental_allocations a WHERE a.payment_id = p.id), 0) ELSE 0 END')->pluck('p.id'));

        // 4. The credit account of each lease equals the credit its payments hold.
        $add('lease_credit', 'Leases whose credit in the journal differs from their payments\' credit', DB::table('rental_leases as l')->where('l.company_id', $companyId)
            ->whereRaw('COALESCE((SELECT SUM(e.credit_minor) FROM rental_entries e WHERE e.lease_id = l.id), 0) <> COALESCE((SELECT SUM(p.unallocated_minor) FROM rental_payments p WHERE p.lease_id = l.id AND p.reversed_at IS NULL), 0)')->pluck('l.id'));

        // 5. An allocation's split adds up to its amount (waivers and refunds split nothing).
        $add('allocation_split', 'Allocations whose split does not add up', DB::table('rental_allocations as a')->where('a.company_id', $companyId)
            ->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('p.method', '!=', 'waiver')->where('c.kind', '!=', 'refund')
            ->whereRaw('a.amount_minor <> a.commission_minor + a.landlord_minor + a.agency_minor + a.deposit_minor')->pluck('a.id'));

        // 6. One live lease per unit.
        $add('unit_two_leases', 'Units with more than one live lease', DB::table('rental_leases')->where('company_id', $companyId)->whereIn('status', ['active', 'notice'])
            ->groupBy('unit_id')->havingRaw('COUNT(*) > 1')->pluck('unit_id'));

        // 7. Reversals: every marked row has its reversal; no reversal of a reversal; no row reversed twice.
        $add('reversal_missing', 'Rows marked reversed with no reversal row', DB::table('rental_entries as e')->where('e.company_id', $companyId)->whereNotNull('e.reversed_at')
            ->whereNotExists(fn ($q) => $q->from('rental_entries as r')->whereColumn('r.reverses_id', 'e.id'))->pluck('e.id'));
        $add('reversal_of_reversal', 'Reversals of reversals', DB::table('rental_entries as r')->where('r.company_id', $companyId)->whereNotNull('r.reverses_id')
            ->whereIn('r.reverses_id', DB::table('rental_entries')->where('company_id', $companyId)->whereNotNull('reverses_id')->select('id'))->pluck('r.id'));
        $add('reversed_twice', 'Rows reversed more than once', DB::table('rental_entries')->where('company_id', $companyId)->whereNotNull('reverses_id')
            ->groupBy('reverses_id')->havingRaw('COUNT(*) > 1')->pluck('reverses_id'));

        // 8. Agency-held deposits: the journal agrees with collected − used − refunded.
        $bad = [];
        foreach (DB::table('rental_leases')->where('company_id', $companyId)->where('deposit_held_by', 'agency')->where('deposit_minor', '>', 0)->pluck('id') as $id) {
            $lease = \App\Models\RentalLease::withoutGlobalScopes()->find($id);
            $journal = (int) DB::table('rental_entries')->where('company_id', $companyId)->where('lease_id', $id)->sum('deposit_minor');
            if ($journal !== Deposits::collected($lease) - Deposits::used($lease) - Deposits::refunded($lease)) {
                $bad[] = $id;
            }
        }
        $add('deposit_held', 'Leases whose deposit in the journal differs from collected − used − refunded', $bad);

        return $p;
    }
}

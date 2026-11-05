<?php

namespace App\Services\Rentals\Jobs;

use App\Models\RentalPayment;
use App\Services\Rentals\Landlords\StatementProof;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proves an account's books (`rentals:check`, docs/estate-ledger/04-architecture.md §5.12): every journal row balances,
 * every cache equals what it caches, nothing is half reversed, and each issued statement still agrees with the journal (LC-05).
 * Read only. Returns the problems found (empty = the books agree), each with the ids to look at.
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
        $add('lease_credit', 'Leases whose credit in the journal differs from their payments\' credit', $this->leaseCreditsAstray($companyId));

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
        $add('deposit_held', 'Leases whose deposit in the journal differs from collected − used − refunded', $this->depositsAstray($companyId));

        // 9. A live lease belongs to its property's landlord.
        $add('lease_landlord', 'Live leases whose landlord differs from their property\'s', DB::table('rental_leases as l')->join('rental_properties as p', 'p.id', '=', 'l.property_id')
            ->where('l.company_id', $companyId)->whereIn('l.status', ['active', 'notice'])->whereColumn('l.landlord_id', '!=', 'p.landlord_id')->pluck('l.id'));

        // 10. What an allocation says went where is what its journal row moved (allocation_journal: LC-05). The reversal of a row is the row's own negative, so originals only.
        $add('allocation_journal', 'Allocations whose landlord, commission or deposit part differs from their journal row', DB::table('rental_entries as e')
            ->join('rental_allocations as a', 'a.id', '=', 'e.allocation_id')->join('rental_leases as l', 'l.id', '=', 'e.lease_id')
            ->where('e.company_id', $companyId)->whereNotNull('e.allocation_id')->whereNull('e.reverses_id')->where('e.type', '!=', 'collected_direct')
            ->whereRaw("(e.income_minor <> a.commission_minor + a.agency_minor
                OR e.landlord_minor <> a.landlord_minor + CASE WHEN e.type = 'deposit_applied' AND l.deposit_held_by = 'landlord' THEN -a.amount_minor WHEN e.type = 'unapplied' AND l.deposit_held_by = 'landlord' THEN a.deposit_minor ELSE 0 END
                OR e.deposit_minor <> CASE WHEN e.type = 'unapplied' AND l.deposit_held_by = 'landlord' THEN 0 WHEN e.type = 'deposit_applied' AND l.deposit_held_by <> 'landlord' THEN a.deposit_minor - a.amount_minor ELSE a.deposit_minor END)")
            ->pluck('a.id'));

        // 11. A tenant who paid the landlord directly (LC-20): only what the agency earned moves, and it moves off the landlord; nothing touches cash, credit or a deposit. No row when it earned nothing.
        $add('direct_journal', 'Payments to the landlord whose journal row differs from what the agency earned', DB::table('rental_allocations as a')
            ->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->leftJoin('rental_entries as e', fn ($j) => $j->on('e.allocation_id', '=', 'a.id')->whereNull('e.reverses_id'))
            ->where('a.company_id', $companyId)->where('p.method', RentalPayment::DIRECT)->whereNull('a.reverses_id')
            ->whereRaw('(COALESCE(e.income_minor, 0) <> a.commission_minor + a.agency_minor OR COALESCE(e.landlord_minor, 0) <> -(a.commission_minor + a.agency_minor)
                OR COALESCE(e.cash_minor, 0) <> 0 OR COALESCE(e.credit_minor, 0) <> 0 OR COALESCE(e.deposit_minor, 0) <> 0)')->pluck('a.id'));

        if (Schema::hasTable('rental_statements')) {
            $this->closedMonths($companyId, $add);
        }

        return $p;
    }

    /** Leases whose credit in the journal differs from what their standing payments hold, in two reads whatever their number. @return list<int> */
    private function leaseCreditsAstray(int $companyId): array
    {
        $journal = array_map('intval', DB::table('rental_entries')->where('company_id', $companyId)->whereNotNull('lease_id')->where('credit_minor', '!=', 0)->groupBy('lease_id')->selectRaw('lease_id, SUM(credit_minor) AS total')->pluck('total', 'lease_id')->all());
        $payments = array_map('intval', DB::table('rental_payments')->where('company_id', $companyId)->whereNull('reversed_at')->where('unallocated_minor', '!=', 0)->groupBy('lease_id')->selectRaw('lease_id, SUM(unallocated_minor) AS total')->pluck('total', 'lease_id')->all());
        $bad = [];
        foreach (array_keys($journal + $payments) as $id) {
            if (($journal[$id] ?? 0) !== ($payments[$id] ?? 0)) {
                $bad[] = (int) $id;
            }
        }

        return $bad;
    }

    /** Leases whose agency-held deposit in the journal differs from collected − used − refunded (Deposits), in four reads whatever their number. @return list<int> */
    private function depositsAstray(int $companyId): array
    {
        $ids = array_map('intval', DB::table('rental_leases')->where('company_id', $companyId)->where('deposit_held_by', 'agency')->where('deposit_minor', '>', 0)->pluck('id')->all());
        if ($ids === []) {
            return [];
        }
        $byLease = fn ($q) => array_map('intval', $q->pluck('total', 'lease_id')->all());
        $collected = $byLease(DB::table('rental_allocations as a')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.company_id', $companyId)->where('c.kind', 'deposit')->where('p.method', '!=', 'waiver')->groupBy('c.lease_id')->selectRaw('c.lease_id, SUM(a.amount_minor) AS total'));
        $used = $byLease(DB::table('rental_payments')->where('company_id', $companyId)->where('method', 'deposit')->whereNull('reversed_at')->groupBy('lease_id')->selectRaw('lease_id, SUM(amount_minor) AS total'));
        $refunded = $byLease(DB::table('rental_entries')->where('company_id', $companyId)->whereIn('type', Deposits::REFUND_TYPES)->whereNull('reversed_at')->groupBy('lease_id')->selectRaw('lease_id, -SUM(cash_minor) AS total'));
        $journal = $byLease(DB::table('rental_entries')->where('company_id', $companyId)->where('deposit_minor', '!=', 0)->groupBy('lease_id')->selectRaw('lease_id, SUM(deposit_minor) AS total'));
        $bad = [];
        foreach ($ids as $id) {
            if (($journal[$id] ?? 0) !== ($collected[$id] ?? 0) - ($used[$id] ?? 0) - ($refunded[$id] ?? 0)) {
                $bad[] = $id;
            }
        }

        return $bad;
    }

    /**
     * The closed months (LC-05): each statement that stands adds up against the journal and the one before it, its body is what its proof says, a close's
     * total is its statements' and the journal's, and nothing was posted into a month after it closed. The months of a reopened close wait until it closes again.
     */
    private function closedMonths(int $companyId, callable $add): void
    {
        $through = DB::table('rental_settings')->where('company_id', $companyId)->value('closed_through');
        if ($through === null) {
            return;
        }
        $standing = fn () => DB::table('rental_statements as s')->where('s.company_id', $companyId)->where('s.status', 'issued')->where('s.month', '<=', $through);

        $add('statement_opening', 'Statements whose opening differs from the journal to the day before their month', $standing()
            ->whereRaw('s.opening_minor <> COALESCE((SELECT SUM(e.landlord_minor) FROM rental_entries e WHERE e.company_id = s.company_id AND e.landlord_id = s.landlord_id AND e.occurred_on < s.month), 0)')->pluck('s.id'));
        $add('statement_closing', 'Statements whose closing differs from the journal to their last day', $standing()
            ->whereRaw('s.closing_minor <> COALESCE((SELECT SUM(e.landlord_minor) FROM rental_entries e WHERE e.company_id = s.company_id AND e.landlord_id = s.landlord_id AND e.occurred_on <= LAST_DAY(s.month)), 0)')->pluck('s.id'));
        $add('statement_chain', 'Statements whose opening differs from the closing of the one before', $standing()
            ->whereRaw("s.opening_minor <> (SELECT p.closing_minor FROM rental_statements p WHERE p.company_id = s.company_id AND p.landlord_id = s.landlord_id AND p.status = 'issued' AND p.month < s.month ORDER BY p.month DESC LIMIT 1)")->pluck('s.id'));

        $badProof = [];
        $badLines = [];
        $standing()->select('s.id', 's.body', 's.proof', 's.opening_minor', 's.closing_minor')->orderBy('s.id')->chunk(100, function ($rows) use (&$badProof, &$badLines) {
            foreach ($rows as $s) {
                $body = (array) json_decode((string) $s->body, true);
                if (StatementProof::of($body) !== (string) $s->proof) {
                    $badProof[] = (int) $s->id;
                }
                if ((int) $s->closing_minor !== (int) $s->opening_minor + array_sum(array_map(fn ($l) => (int) ($l['amount_minor'] ?? 0), (array) ($body['lines'] ?? [])))) {
                    $badLines[] = (int) $s->id;
                }
            }
        });
        $add('statement_proof', 'Statements whose body no longer matches their proof', $badProof);
        $add('statement_lines', 'Statements whose closing differs from their opening plus their lines', $badLines);

        $badTotal = [];
        $badAfter = [];
        foreach (DB::table('rental_closes')->where('company_id', $companyId)->where('status', 'closed')->where('month', '<=', $through)->get(['id', 'month', 'totals', 'closed_at']) as $c) {
            $last = Carbon::parse((string) $c->month)->endOfMonth()->toDateString();
            $t = (array) json_decode((string) $c->totals, true);
            $owed = (int) ($t['owed_to_landlords'] ?? 0) - (int) ($t['owed_back_by_landlords'] ?? 0);
            $statements = (int) DB::table('rental_statements')->where('company_id', $companyId)->where('status', 'issued')->where('month', $c->month)->sum('closing_minor');
            $journal = (int) DB::table('rental_entries')->where('company_id', $companyId)->where('occurred_on', '<=', $last)->sum('landlord_minor');
            if ($owed !== $statements || $owed !== $journal) {
                $badTotal[] = (int) $c->id;
            }
            $badAfter = array_merge($badAfter, array_map('intval', DB::table('rental_entries')->where('company_id', $companyId)->where('occurred_on', '<=', $last)->where('created_at', '>', $c->closed_at)->limit(50)->pluck('id')->all()));
        }
        $add('close_total', 'Closes whose total differs from their statements or the journal', $badTotal);
        $add('closed_after', 'Rows dated in a closed month and written after it closed', array_values(array_unique($badAfter)));

        // The latest closed month's commission, on the allocations and on the commission report.
        $month = Carbon::parse((string) $through)->startOfMonth();
        $onAllocations = (int) DB::table('rental_allocations as a')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->where('a.company_id', $companyId)->where('p.method', '!=', 'waiver')
            ->whereBetween('a.applied_on', [$month->toDateString(), (string) $through])->sum('a.commission_minor');
        if ($onAllocations !== $this->commissionOnReport($companyId, $month->toDateString(), (string) $through)) {
            $add('commission_report', 'The commission report differs from the allocations for the latest closed month', [(int) DB::table('rental_closes')->where('company_id', $companyId)->where('status', 'closed')->orderByDesc('month')->orderByDesc('version')->value('id')]);
        }
    }

    private function commissionOnReport(int $companyId, string $from, string $to): int
    {
        if (RentalsModule::isOwnerMode($companyId)) {
            return (int) DB::table('rental_allocations as a')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->where('a.company_id', $companyId)->where('p.method', '!=', 'waiver')
                ->whereBetween('a.applied_on', [$from, $to])->sum('a.commission_minor'); // an owner has no commission report
        }

        return (int) ((new RentalReports)->run('commission', $companyId, null, ['from' => $from, 'to' => $to])['totals']['commission_minor'] ?? 0);
    }
}

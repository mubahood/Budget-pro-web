<?php

namespace App\Services\Rentals\Landlords;

use App\Models\RentalLease;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A month before it is final (LC-04): read from the journal in a few batched queries, whatever the number of landlords, with each landlord's figures
 * added up by the very rule a statement adds up by (LandlordAccounts::addToTotals), so the screen and the statements cannot differ. Read only.
 */
final class MonthEndFigures
{
    /**
     * The month's figures: one row per landlord with any balance, line or unit that month, the account's totals, and last month's beside them.
     *
     * @param  string  $month  Y-m
     * @return array{month: string, from: string, to: string, rows: list<array<string, mixed>>, totals: array<string, mixed>, last: array<string, mixed>}
     */
    public static function month(int $companyId, string $month, bool $withLast = true): array
    {
        $from = $month.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $rows = self::byLandlord($companyId, $from, $to);
        $last = [];
        if ($withLast) {
            $before = Carbon::parse($from)->subMonthNoOverflow();
            $last = self::totals($companyId, self::byLandlord($companyId, $before->copy()->startOfMonth()->toDateString(), $before->copy()->endOfMonth()->toDateString()), $before->copy()->endOfMonth()->toDateString());
        }

        return ['month' => $month, 'from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => self::totals($companyId, $rows, $to), 'last' => $last];
    }

    /**
     * One row per landlord, by name: opening, collected, commission and its effective rate, deposits, expenses, payouts, other, closing, the same as their statement.
     *
     * @return list<array{landlord_id: int, name: string, code: ?string, opening_minor: int, collected_minor: int, commission_minor: int, direct_minor: int, rate_bp: int, deposits_minor: int, expenses_minor: int, payouts_minor: int, other_minor: int, closing_minor: int, lines: int}>
     */
    public static function byLandlord(int $companyId, string $from, string $to): array
    {
        $opening = array_map('intval', DB::table('rental_entries')->where('company_id', $companyId)->whereNotNull('landlord_id')->where('occurred_on', '<', $from)
            ->groupBy('landlord_id')->selectRaw('landlord_id, SUM(landlord_minor) AS b')->pluck('b', 'landlord_id')->all());
        $until = Carbon::parse($to, LocalDate::timezone($companyId))->endOfDay()->utc();
        $withUnits = array_map('intval', DB::table('rental_units as u')->join('rental_properties as p', 'p.id', '=', 'u.property_id')->where('u.company_id', $companyId)->where('p.status', 'active')
            ->where('u.status', '!=', 'inactive')->where('u.created_at', '<=', $until)->distinct()->pluck('p.landlord_id')->all());
        $zero = ['collected_minor' => 0, 'commission_minor' => 0, 'expenses_minor' => 0, 'payouts_minor' => 0, 'deposits_minor' => 0, 'other_minor' => 0];
        $direct = LandlordAccounts::directBy($companyId, $from, $to);
        $totals = [];
        $lines = [];
        $moved = DB::table('rental_entries as e')->leftJoin('rental_entries as o', fn ($j) => $j->on('o.id', '=', 'e.reverses_id')->where('e.type', 'reversal'))
            ->leftJoin('rental_allocations as a', 'a.id', '=', DB::raw('CASE WHEN o.id IS NOT NULL THEN o.allocation_id ELSE e.allocation_id END'))->leftJoin('rental_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('e.company_id', $companyId)->whereNotNull('e.landlord_id')->whereBetween('e.occurred_on', [$from, $to])->where('e.landlord_minor', '!=', 0)
            ->get(['e.landlord_id', 'e.type', 'e.landlord_minor', 'o.id as original', 'o.type as original_type', 'a.id as allocation', 'a.amount_minor as alloc_amount', 'a.commission_minor as alloc_commission', 'a.landlord_minor as alloc_landlord', 'c.kind as charge_kind']);
        foreach ($moved as $r) {
            $id = (int) $r->landlord_id;
            $totals[$id] ??= $zero;
            $lines[$id] = ($lines[$id] ?? 0) + 1;
            $sign = $r->original !== null ? -1 : 1;
            $hasAlloc = $r->allocation !== null;
            $gross = $hasAlloc ? $sign * (int) $r->alloc_amount : 0;
            $commission = $hasAlloc ? $sign * (int) $r->alloc_commission : 0;
            LandlordAccounts::addToTotals($totals[$id], (string) ($r->original !== null ? $r->original_type : $r->type), $gross, $commission, (int) $r->landlord_minor,
                $hasAlloc && (int) $r->alloc_landlord > 0 && (int) $r->alloc_commission === 0 && $r->charge_kind === 'deposit');
        }
        $ids = array_values(array_unique(array_merge(array_keys($totals), array_keys(array_filter($opening, fn ($b) => $b !== 0)), $withUnits)));
        $rows = [];
        foreach (DB::table('rental_landlords')->where('company_id', $companyId)->whereIn('id', $ids ?: [0])->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']) as $l) {
            $id = (int) $l->id;
            $t = $totals[$id] ?? $zero;
            $open = $opening[$id] ?? 0;
            $closing = $open + $t['collected_minor'] - $t['commission_minor'] + $t['deposits_minor'] - $t['expenses_minor'] - $t['payouts_minor'] + $t['other_minor'];
            $earnedOn = $t['collected_minor'] + ($direct[$id] ?? 0); // commission is earned on rent paid to the landlord too
            $rows[] = ['landlord_id' => $id, 'name' => (string) $l->name, 'code' => $l->code, 'opening_minor' => $open] + $t
                + ['direct_minor' => $direct[$id] ?? 0, 'rate_bp' => $earnedOn > 0 ? (int) round($t['commission_minor'] * 10000 / $earnedOn) : 0, 'closing_minor' => $closing, 'lines' => $lines[$id] ?? 0];
        }

        return $rows;
    }

    /**
     * What the screen asks to look at before closing, each a count: bills not raised, reports waiting, expenses with no receipt, landlords below zero.
     *
     * @return array{bills_missing: int, reports_waiting: int, expenses_no_receipt: int, below_zero: int, below_zero_minor: int, agency_own_minor: int}
     */
    public static function lookAt(int $companyId, string $month): array
    {
        $from = $month.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        // A live lease whose next period starts by the month's last day and has no bill: raised late, or the nightly run missed it.
        $bills = RentalLease::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', RentalLease::LIVE)->where('start_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('billed_until')->orWhere('billed_until', '<', $to))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereNull('billed_until')->orWhereColumn('end_date', '>', 'billed_until'))
            ->where(fn ($q) => $q->whereNull('move_out_on')->orWhereNull('billed_until')->orWhereColumn('move_out_on', '>', 'billed_until'))->count();
        $reports = DB::table('rental_paid_reports')->where('company_id', $companyId)->where('status', 'pending')->count();
        $noReceipt = DB::table('rental_expenses')->where('company_id', $companyId)->whereNull('reversed_at')->whereBetween('paid_on', [$from, $to])->whereNull('receipt_uuid')->count();
        $balances = DB::table('rental_entries')->where('company_id', $companyId)->whereNotNull('landlord_id')->where('occurred_on', '<=', $to)->groupBy('landlord_id')
            ->havingRaw('SUM(landlord_minor) < 0')->selectRaw('SUM(landlord_minor) AS b')->pluck('b');

        return ['bills_missing' => $bills, 'reports_waiting' => $reports, 'expenses_no_receipt' => $noReceipt, 'below_zero' => $balances->count(),
            'below_zero_minor' => (int) $balances->sum(), 'agency_own_minor' => MoneyPosition::asOf($companyId, $to)['agency_own_minor']];
    }

    /**
     * The account's figures for a month: added up from the landlords' rows, with the position and the cash per account on its last day.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function totals(int $companyId, array $rows, string $to): array
    {
        $sum = fn (string $k) => array_sum(array_map(fn ($r) => (int) $r[$k], $rows));
        $pos = MoneyPosition::asOf($companyId, $to);
        $cash = array_fill_keys(['cash', 'bank', 'mobile_money', 'cheque'], 0);
        foreach (DB::table('rental_entries')->where('company_id', $companyId)->whereNotNull('method')->where('occurred_on', '<=', $to)->groupBy('method')->selectRaw('method, SUM(cash_minor) AS total')->pluck('total', 'method') as $method => $total) {
            if (array_key_exists($method, $cash)) {
                $cash[$method] = (int) $total;
            }
        }

        return ['collected' => $sum('collected_minor'), 'direct' => $sum('direct_minor'), 'commission' => $sum('commission_minor'), 'landlord_share' => $sum('collected_minor') - $sum('commission_minor'), 'deposits_passed' => $sum('deposits_minor'),
            'expenses' => $sum('expenses_minor'), 'payouts' => $sum('payouts_minor'), 'other' => $sum('other_minor'), 'owed_to_landlords' => $pos['owed_to_landlords_minor'],
            'owed_back_by_landlords' => $pos['owed_back_by_landlords_minor'], 'deposits' => $pos['deposits_minor'], 'credit' => $pos['credit_minor'], 'cash' => $cash, 'cash_total' => $pos['cash_minor'], 'agency_own' => $pos['agency_own_minor']];
    }
}

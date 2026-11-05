<?php

namespace App\Services\Rentals\Landlords;

use App\Models\RentalCharge;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalLeaseItem;
use App\Models\RentalPayment;
use App\Models\RentalRentChange;
use App\Services\Rentals\Leases\Leases;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A landlord's units as they stood on a day (LC-06): the rent sheet and the statement's unit by unit table (LC-08) read it. Every figure
 * is worked out from dated rows, so a past month reads the same whenever it is asked for: a tenant paying later changes nothing.
 *
 * Paid follows the journal row by row with the statement's own rule (LandlordAccounts::statement), so the column's total is the
 * statement's "collected" for that month: rent and fees, allocations by the day they were applied, waivers and deposits not counted.
 * Billed and owed are the landlord's own bills, the same bills as the statement's "still owing": deposits are not among them. What a tenant paid the landlord directly
 * (LC-20) is in `direct_minor`, never in paid. A room the landlord or caretaker uses (LC-21) has `in_use_by` and no `vacant_since`.
 */
final class UnitSheet
{
    /** Journal rows that carry money from a tenant onto a landlord's account (LandlordAccounts::statement). */
    private const COLLECTED = ['collected', 'credit_applied', 'deposit_applied', 'unapplied'];

    /**
     * One row per unit of the landlord's properties that day: its tenant, or a vacant row with `vacant_since`. A former tenant of the
     * unit, or a tenant whose older bills stayed with this landlord after a handover, has a row of their own when they have money in the
     * month or still owe, with `moved_out_on` when they have left, so that no shilling is put on the wrong name. Figures are as of $asOf
     * and reproduce. `months_behind` is whole months of rent owed, floored, and `behind_rest_minor` what is left over. A vacant row's rent
     * is zero: none is in force.
     *
     * @param  string  $asOf  Y-m-d: a month's rows are as of its last day, and billed and paid are that month's
     * @param  list<int>|null  $ownedProperties  Handover::propertyIdsOn for that day, when the caller has it
     * @return list<array{unit_id: int, unit: string, property_id: int, property: string, lease_id: ?int, tenant_id: ?int, tenant: ?string, origin: ?string, vacant_since: ?string, in_use_by: ?string, moved_out_on: ?string, rent_minor: int, billed_minor: int, paid_minor: int, direct_minor: int, owed_minor: int, months_behind: int, behind_rest_minor: int, paid_up_to: ?string, last_paid_on: ?string, last_paid_minor: ?int}>
     */
    public static function rows(RentalLandlord $landlord, string $asOf, ?array $ownedProperties = null): array
    {
        $cid = (int) $landlord->company_id;
        $day = Carbon::parse($asOf)->toDateString();
        $from = Carbon::parse($day)->startOfMonth()->toDateString();
        $timezone = LocalDate::timezone($cid);
        $until = Carbon::parse($day, $timezone)->endOfDay()->utc()->toDateTimeString();

        $bills = self::bills($cid, (int) $landlord->id, $from, $day, $until);
        $paid = self::paid($cid, (int) $landlord->id, $from, $day);
        $direct = self::direct($cid, (int) $landlord->id, $from, $day);
        $owned = self::unitsOn($cid, $ownedProperties ?? Handover::propertyIdsOn($cid, (int) $landlord->id, $day), $until);
        $ownedIds = $owned->pluck('id')->map(fn ($v) => (int) $v)->all();
        $leases = RentalLease::withoutGlobalScopes()->select('rental_leases.*')->selectSub(DB::table('rental_tenants as t')->whereColumn('t.id', 'rental_leases.tenant_id')->limit(1)->select('t.name'), 'tenant_name')
            ->where('company_id', $cid)->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('unit_id', $ownedIds ?: [0])->where('start_date', '<=', $day))->orWhereIn('id', array_keys($bills + $paid + $direct) ?: [0]))
            ->orderBy('start_date')->orderBy('id')->get();

        // Who holds each unit that day, and everyone else on it who has money to show.
        $current = [];
        foreach ($leases as $l) {
            if (in_array((int) $l->unit_id, $ownedIds, true) && $l->start_date->toDateString() <= $day && ($l->ended_on === null || $l->ended_on->toDateString() >= $day)) {
                $current[(int) $l->unit_id] = (int) $l->id; // the last one started, if a move-out and a move-in share the day
            }
        }
        $empty = ['billed' => 0, 'owed' => 0];
        $shown = $leases->filter(function (RentalLease $l) use ($current, $bills, $paid, $direct, $empty) {
            $b = $bills[(int) $l->id] ?? $empty;

            return ($current[(int) $l->unit_id] ?? null) === (int) $l->id || $b['billed'] !== 0 || $b['owed'] !== 0 || ($paid[(int) $l->id] ?? 0) !== 0 || ($direct[(int) $l->id] ?? 0) !== 0;
        })->values();

        $facts = self::facts($cid, $shown, $current, $day, $until);
        $others = array_values(array_diff($shown->pluck('unit_id')->map(fn ($v) => (int) $v)->unique()->all(), $ownedIds));
        $units = $owned->concat($others === [] ? [] : self::units($cid, fn ($q) => $q->whereIn('u.id', $others))->all())->keyBy('id');

        $rows = [];
        foreach ($shown as $l) {
            $unit = $units[(int) $l->unit_id] ?? null;
            if ($unit === null) {
                continue;
            }
            $id = (int) $l->id;
            $b = $bills[$id] ?? $empty;
            $rent = $facts['rent'][$id];
            $isCurrent = ($current[(int) $l->unit_id] ?? null) === $id;
            $rows[] = self::row($unit, ['lease_id' => $id, 'tenant_id' => (int) $l->tenant_id, 'tenant' => (string) $l->getAttribute('tenant_name'), 'origin' => (string) $l->origin,
                'moved_out_on' => $isCurrent ? null : $l->ended_on?->toDateString(), 'rent_minor' => $rent, 'billed_minor' => $b['billed'], 'paid_minor' => (int) ($paid[$id] ?? 0), 'direct_minor' => (int) ($direct[$id] ?? 0),
                'owed_minor' => $b['owed'], 'months_behind' => $rent > 0 ? intdiv($b['owed'], $rent) : 0, 'behind_rest_minor' => $rent > 0 ? $b['owed'] % $rent : $b['owed'],
                'paid_up_to' => $facts['paid_up_to'][$id], 'last_paid_on' => $facts['last'][$id]['on'] ?? null, 'last_paid_minor' => $facts['last'][$id]['minor'] ?? null], 1, (string) $l->start_date->toDateString());
        }
        foreach ($owned as $u) {
            if (! isset($current[(int) $u->id]) && ($unit = $units[(int) $u->id] ?? null) !== null) {
                $ended = $leases->where('unit_id', (int) $u->id)->map(fn (RentalLease $l) => $l->ended_on?->toDateString())->filter(fn ($d) => $d !== null && $d < $day)->max();
                $since = $ended !== null ? Carbon::parse($ended)->addDay()->toDateString() : Carbon::parse($unit->created_at)->setTimezone($timezone)->toDateString();
                $rows[] = self::row($unit, ($unit->status === 'in_use' ? ['in_use_by' => (string) $unit->in_use_by] : ['vacant_since' => $since]), 2, '');
            }
        }
        usort($rows, fn ($a, $b) => [$a['property'], $a['property_id']] <=> [$b['property'], $b['property_id']] ?: (strnatcasecmp($a['unit'], $b['unit']) ?: [$a['_order'], $a['_start']] <=> [$b['_order'], $b['_start']]));

        return array_map(function (array $r) {
            unset($r['_order'], $r['_start']);

            return $r;
        }, $rows);
    }

    /** "2 months + 40,000" (the landlord's own way of saying it), or an empty string when nothing is owed. */
    public static function behindWords(int $months, int $rest, int $companyId): string
    {
        $figure = fn (int $v) => (string) preg_replace('/^[^\d]+/', '', Minor::format($v, $companyId));
        $words = array_filter([$months > 0 ? number_format($months).($months === 1 ? ' month' : ' months') : '', $rest > 0 ? $figure($rest) : '']);

        return implode(' + ', $words);
    }

    /**
     * Rent and fees collected per landlord in a period: the statement's "collected" for each, in one read. For the landlords' list.
     *
     * @param  list<int>  $landlordIds
     * @return array<int, int>
     */
    public static function collected(int $companyId, array $landlordIds, string $from, string $to): array
    {
        return array_map(fn (array $byLease) => array_sum($byLease), self::paidBy($companyId, $landlordIds, $from, $to));
    }

    /**
     * One row, with the defaults a vacant unit has.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private static function row(object $unit, array $over, int $order, string $start): array
    {
        return $over + ['unit_id' => (int) $unit->id, 'unit' => (string) $unit->code, 'property_id' => (int) $unit->property_id, 'property' => (string) $unit->property,
            'lease_id' => null, 'tenant_id' => null, 'tenant' => null, 'origin' => null, 'vacant_since' => null, 'in_use_by' => null, 'moved_out_on' => null, 'rent_minor' => 0, 'billed_minor' => 0, 'paid_minor' => 0, 'direct_minor' => 0,
            'owed_minor' => 0, 'months_behind' => 0, 'behind_rest_minor' => 0, 'paid_up_to' => null, 'last_paid_on' => null, 'last_paid_minor' => null,
            '_order' => $order, '_start' => $start];
    }

    /**
     * Units of the properties this landlord owned that day (the statement's own rule for which units count): switched-off units, archived
     * properties and units not yet added are out. Ownership history decides when a property changed hands.
     *
     * @param  list<int>  $properties
     * @return Collection<int, \stdClass>
     */
    private static function unitsOn(int $cid, array $properties, string $until): Collection
    {
        return self::units($cid, fn ($q) => $q->where('p.status', 'active')->whereIn('p.id', $properties ?: [0])
            ->where('u.status', '!=', 'inactive')->where('u.created_at', '<=', $until));
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): mixed  $which
     * @return Collection<int, \stdClass>
     */
    private static function units(int $cid, callable $which): Collection
    {
        $q = DB::table('rental_units as u')->join('rental_properties as p', 'p.id', '=', 'u.property_id')->where('u.company_id', $cid);
        $which($q);

        return $q->get(['u.id', 'u.code', 'u.property_id', 'u.created_at', 'u.status', 'u.in_use_by', 'p.name AS property']);
    }

    /**
     * Per lease: billed in the month and owed at the end, on the landlord's own bills as they stood that day (LandlordAccounts::arrears' rule).
     *
     * @return array<int, array{billed: int, owed: int}>
     */
    private static function bills(int $cid, int $landlordId, string $from, string $day, string $until): array
    {
        $rows = DB::table('rental_charges as c')->where('c.company_id', $cid)->where('c.landlord_id', $landlordId)->where('c.goes_to', 'landlord')->where('c.due_date', '<=', $day)
            ->where(fn ($q) => $q->where('c.status', '!=', 'void')->orWhere('c.voided_at', '>', $until))
            ->selectRaw('c.lease_id, c.due_date, c.amount_minor, c.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM rental_allocations a WHERE a.company_id = c.company_id AND a.charge_id = c.id AND a.applied_on <= ?), 0) AS owed', [$day])
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->lease_id;
            $out[$id] ??= ['billed' => 0, 'owed' => 0];
            $out[$id]['owed'] += max(0, (int) $r->owed);
            if ($r->due_date >= $from) {
                $out[$id]['billed'] += (int) $r->amount_minor;
            }
        }

        return array_filter($out, fn (array $b) => $b['billed'] !== 0 || $b['owed'] !== 0);
    }

    /**
     * Rent and fees collected in the month per lease, line for line the statement's "collected": the same journal rows, the same deposit rule.
     *
     * @return array<int, int>
     */
    private static function paid(int $cid, int $landlordId, string $from, string $day): array
    {
        return self::paidBy($cid, [$landlordId], $from, $day)[$landlordId] ?? [];
    }

    /**
     * What tenants paid this landlord directly in the month, per lease, net of reversals (LC-20).
     *
     * @return array<int, int>
     */
    private static function direct(int $cid, int $landlordId, string $from, string $day): array
    {
        return array_filter(array_map('intval', DB::table('rental_allocations as a')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('a.company_id', $cid)->where('c.landlord_id', $landlordId)->where('p.method', RentalPayment::DIRECT)->whereBetween('a.applied_on', [$from, $day])
            ->groupBy('c.lease_id')->selectRaw('c.lease_id, SUM(a.amount_minor) AS total')->pluck('total', 'lease_id')->all()));
    }

    /**
     * paid() for many landlords.
     *
     * @param  list<int>  $landlordIds
     * @return array<int, array<int, int>> landlord id => lease id => collected
     */
    private static function paidBy(int $cid, array $landlordIds, string $from, string $day): array
    {
        $rows = DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')->leftJoin('rental_allocations as a', 'a.id', '=', 'e.allocation_id')
            ->leftJoin('rental_charges as c', 'c.id', '=', 'a.charge_id')->where('e.company_id', $cid)->whereIn('e.landlord_id', $landlordIds ?: [0])->whereBetween('e.occurred_on', [$from, $day])
            ->where('e.landlord_minor', '!=', 0)->whereNotNull('e.lease_id')->whereNotNull('e.allocation_id')
            ->get(['e.landlord_id', 'e.lease_id', 'e.type', 'o.type AS original', 'e.landlord_minor', 'a.amount_minor', 'a.commission_minor', 'a.landlord_minor AS alloc_landlord', 'c.kind']);
        $out = [];
        foreach ($rows as $r) {
            $reversal = $r->type === 'reversal';
            $kind = (string) ($reversal ? $r->original : $r->type);
            if (! in_array($kind, self::COLLECTED, true)) {
                continue;
            }
            $sign = $reversal ? -1 : 1;
            $totals = ['collected_minor' => 0, 'commission_minor' => 0, 'deposits_minor' => 0, 'payouts_minor' => 0, 'expenses_minor' => 0, 'other_minor' => 0];
            LandlordAccounts::addToTotals($totals, $kind, $sign * (int) $r->amount_minor, $sign * (int) $r->commission_minor, (int) $r->landlord_minor,
                (int) $r->alloc_landlord > 0 && (int) $r->commission_minor === 0 && $r->kind === 'deposit');
            $who = (int) $r->landlord_id;
            $id = (int) $r->lease_id;
            $out[$who][$id] = ($out[$who][$id] ?? 0) + $totals['collected_minor'];
        }

        return array_map(fn (array $leases) => array_filter($leases, fn (int $v) => $v !== 0), $out);
    }

    /**
     * Rent in force, paid up to and last paid per lease as of the day, in a handful of reads whatever the number of leases.
     *
     * @param  Collection<int, RentalLease>  $leases
     * @param  array<int, int>  $current  unit id => the lease holding it that day
     * @return array{rent: array<int, int>, paid_up_to: array<int, ?string>, last: array<int, array{on: string, minor: int}>}
     */
    private static function facts(int $cid, Collection $leases, array $current, string $day, string $until): array
    {
        $out = ['rent' => [], 'paid_up_to' => [], 'last' => []];
        $ids = $leases->map(fn (RentalLease $l) => (int) $l->id)->all();
        if ($ids === []) {
            return $out;
        }
        $changes = RentalRentChange::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ids)->where('effective_from', '<=', $day)
            ->orderByDesc('effective_from')->orderByDesc('id')->get(['lease_id', 'effective_from', 'rent_minor'])->groupBy('lease_id');
        $rents = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ids)->where('kind', 'rent')
            ->where(fn ($q) => $q->where('status', '!=', 'void')->orWhere('voided_at', '>', $until))
            ->selectRaw('rental_charges.*, COALESCE((SELECT SUM(a.amount_minor) FROM rental_allocations a WHERE a.company_id = rental_charges.company_id AND a.charge_id = rental_charges.id AND a.applied_on <= ?), 0) AS paid_then', [$day])
            ->orderBy('period_start')->get()->groupBy('lease_id');
        $credit = array_map('intval', DB::table('rental_entries')->where('company_id', $cid)->whereIn('lease_id', $ids)->where('occurred_on', '<=', $day)
            ->groupBy('lease_id')->selectRaw('lease_id, SUM(credit_minor) AS c')->pluck('c', 'lease_id')->all());
        $items = array_map('intval', RentalLeaseItem::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ids)->where('starts_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))->where(fn ($q) => $q->where('active', true)->orWhere('updated_at', '>', $until))
            ->groupBy('lease_id')->selectRaw('lease_id, SUM(amount_minor) AS a')->pluck('a', 'lease_id')->all());
        foreach ($leases as $l) {
            $id = (int) $l->id;
            $mine = $changes->get($id, collect());
            $out['rent'][$id] = (int) ($mine->first()->rent_minor ?? $l->rent_minor);
            // The rule of Leases::paidUpToFrom, on copies set back to that day: bills as paid then, nothing raised later, live if it held the unit.
            $then = $rents->get($id, collect())->filter(fn (RentalCharge $c) => $c->due_date->toDateString() <= $day || (int) $c->getAttribute('paid_then') > 0)->map(function (RentalCharge $c) {
                $copy = clone $c;
                $copy->paid_minor = (int) $c->getAttribute('paid_then');
                $copy->status = $copy->paid_minor >= (int) $c->amount_minor ? 'paid' : 'open';

                return $copy;
            })->values();
            $at = clone $l;
            $at->billed_until = $then->isEmpty() ? null : $then->max(fn (RentalCharge $c) => $c->period_end);
            $at->status = ($current[(int) $l->unit_id] ?? null) === $id ? 'active' : 'ended';
            $out['paid_up_to'][$id] = Leases::paidUpToFrom($at, $then, max(0, (int) ($credit[$id] ?? 0)), (int) ($items[$id] ?? 0), $mine);
        }
        $paid = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ids)->whereIn('method', [...RentalPayment::CASH_METHODS, RentalPayment::DIRECT])->where('paid_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('reversed_at')->orWhere('reversed_at', '>', $until))->orderBy('paid_on')->orderBy('id')->get(['lease_id', 'paid_on', 'amount_minor']);
        foreach ($paid as $p) {
            $out['last'][(int) $p->lease_id] = ['on' => $p->paid_on->toDateString(), 'minor' => (int) $p->amount_minor];
        }

        return $out;
    }
}

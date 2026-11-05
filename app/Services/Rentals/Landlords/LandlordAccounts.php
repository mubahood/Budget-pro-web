<?php

namespace App\Services\Rentals\Landlords;

use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalUnit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A landlord's account with the agency (cash basis, KR-08): what was collected for them less commission, expenses
 * paid for their properties, payouts. The balance is Σ `landlord_minor` of their journal rows; a statement for any
 * period is the same every time it is printed (ADR 0010).
 */
final class LandlordAccounts
{
    /** Row types and how a statement words them. */
    public const LABELS = [
        'collected' => 'Rent and fees collected', 'credit_applied' => 'Paid from the tenant\'s credit', 'deposit_applied' => 'Paid from the deposit',
        'unapplied' => 'Taken back to the tenant\'s credit', 'payout' => 'Paid to you', 'expense_landlord' => 'Expense',
        'deposit_refund_landlord' => 'Deposit refunded for you', 'opening_landlord' => 'Opening balance', 'reversal' => 'Correction', 'collected_direct' => 'Tenant paid you directly',
    ];

    /**
     * What the agency already owed a landlord when it moved to Estate Ledger (negative: the landlord owed the agency). The
     * cash for it is in the opening cash (CashBook::opening).
     */
    public static function opening(\App\Models\User $by, RentalLandlord $landlord, int $amount, Carbon $on): ?\App\Models\RentalEntry
    {
        \App\Services\Rentals\RentalAccess::authorize($by, 'rentals_settings');
        if ($amount === 0) {
            return null;
        }
        $cid = (int) $landlord->company_id;

        return \App\Services\Rentals\Ledger\RentalLedger::locked($cid, fn () => \App\Services\Rentals\Ledger\RentalLedger::post($cid, \App\Services\Rentals\Ledger\RentalLedger::group(), [
            ['type' => 'opening_landlord', 'occurred_on' => $on->toDateString(), 'landlord_minor' => $amount, 'equity_minor' => -$amount, 'landlord_id' => $landlord->id,
                'description' => 'Opening balance'],
        ], $by->id)[0] ?? null);
    }

    /** What the agency owes the landlord on $asOf (negative: the landlord owes the agency). */
    public static function balance(RentalLandlord $landlord, ?string $asOf = null): int
    {
        $q = RentalEntry::withoutGlobalScopes()->where('company_id', $landlord->company_id)->where('landlord_id', $landlord->id);
        if ($asOf !== null) {
            $q->where('occurred_on', '<=', $asOf);
        }

        return (int) $q->sum('landlord_minor');
    }

    /** Balances of every landlord of an account. @return array<int, int> landlord id => balance */
    public static function balances(int $companyId): array
    {
        return array_map('intval', DB::table('rental_entries')->where('company_id', $companyId)->whereNotNull('landlord_id')
            ->groupBy('landlord_id')->selectRaw('landlord_id, SUM(landlord_minor) AS b')->pluck('b', 'landlord_id')->all());
    }

    /**
     * The statement for a period (04 §9.1 Statement). $owned: the properties the landlord owned on $to, when the caller already has them.
     *
     * @param  list<int>|null  $owned
     * @return array<string, mixed>
     */
    public static function statement(RentalLandlord $landlord, string $from, string $to, ?array $owned = null): array
    {
        $cid = (int) $landlord->company_id;
        $opening = self::balance($landlord, Carbon::parse($from)->subDay()->toDateString());
        $rows = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $landlord->id)
            ->whereBetween('occurred_on', [$from, $to])->where('landlord_minor', '!=', 0)->orderBy('occurred_on')->orderBy('id')->get();
        $allocs = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $rows->pluck('allocation_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $reversing = $rows->pluck('reverses_id')->filter()->all();
        $originals = $reversing === [] ? collect() : RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $reversing)->get()->keyBy('id');
        $origAllocs = $reversing === [] ? collect() : RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $originals->pluck('allocation_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $names = self::names($cid, $rows);
        $chargeKinds = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $allocs->pluck('charge_id')->concat($origAllocs->pluck('charge_id'))->filter()->unique()->values()->all() ?: [0])->pluck('kind', 'id');
        $issued = \App\Services\Rentals\Payments\Receipt::hasSnapshots() ? \App\Models\RentalPayment::withoutGlobalScopes()->where('company_id', $cid)
            ->whereIn('id', $rows->pluck('payment_id')->filter()->unique()->all() ?: [0])->get(['id', 'receipt_snapshot'])->keyBy('id') : collect();
        $marks = self::lateMarks($cid, $rows);
        $totals = ['collected_minor' => 0, 'commission_minor' => 0, 'expenses_minor' => 0, 'payouts_minor' => 0, 'deposits_minor' => 0, 'other_minor' => 0];
        $lines = [];
        foreach ($rows as $r) {
            $sign = 1;
            $src = $r;
            $alloc = $r->allocation_id ? $allocs->get($r->allocation_id) : null;
            if ($r->type === 'reversal' && ($o = $originals->get($r->reverses_id)) !== null) {
                $src = $o;
                $sign = -1;
                $alloc = $o->allocation_id ? $origAllocs->get($o->allocation_id) : null;
            }
            $gross = $alloc !== null ? $sign * (int) $alloc->amount_minor : 0;
            $commission = $alloc !== null ? $sign * (int) $alloc->commission_minor : 0;
            $amount = (int) $r->landlord_minor;
            $kind = $src->type;
            self::addToTotals($totals, $kind, $gross, $commission, $amount, $alloc !== null && (int) $alloc->landlord_minor > 0 && (int) $alloc->commission_minor === 0
                && ($chargeKinds[$alloc->charge_id] ?? null) === 'deposit');
            $as = $issued->get($r->payment_id)?->receipt_snapshot['names'] ?? [];
            $isDirect = $kind === 'collected_direct';
            $lines[] = [
                'date' => $r->occurred_on->toDateString(), 'type' => $r->type === 'reversal' ? 'reversal' : $kind,
                'description' => ($r->type === 'reversal' ? 'Correction: ' : '').($isDirect ? self::directWords($cid, abs($gross), abs($commission), abs($amount) - abs($commission), (string) $src->description) : ($src->description ?: (self::LABELS[$kind] ?? $kind))).self::markOf($marks, $r),
                'property' => $as['property'] ?? $names['property'][$r->property_id] ?? null, 'unit' => $as['unit'] ?? $names['unit'][$r->unit_id] ?? null,
                'tenant' => $as['tenant'] ?? $names['tenant'][$r->tenant_id] ?? null, 'gross_minor' => $isDirect ? 0 : $gross, 'commission_minor' => $commission, 'amount_minor' => $amount,
                'direct_minor' => $isDirect ? $gross : 0,
            ];
        }
        $paidDirect = self::directAllocations($cid, (int) $landlord->id, $from, $to);
        if ($paidDirect->isNotEmpty()) {
            $totals['direct_minor'] = (int) $paidDirect->sum('amount_minor'); // said only when a tenant did: information beside the figures, never in them
            $lines = self::withSilentDirect($lines, $paidDirect, $cid, $names);
        }
        $closing = $opening + array_sum(array_column($lines, 'amount_minor'));

        return [
            'landlord' => ['id' => (int) $landlord->id, 'name' => (string) $landlord->name], 'from' => $from, 'to' => $to,
            'currency' => RentalsModule::settings($cid)->currency, 'opening_minor' => $opening, 'closing_minor' => $closing,
            'lines' => $lines, 'totals' => $totals, 'occupancy' => self::occupancy($landlord, $to, $owned), 'arrears' => self::arrears($landlord, $to),
        ];
    }

    /**
     * One journal row into a statement's totals (the statement, and MonthEndFigures for every landlord at once).
     *
     * @param  array<string, int>  $totals
     * @param  string  $kind  the row's type, or the type of the row it reverses
     * @param  bool  $isDeposit  a deposit bill's money passed to the landlord
     */
    public static function addToTotals(array &$totals, string $kind, int $gross, int $commission, int $amount, bool $isDeposit): void
    {
        if (in_array($kind, ['collected', 'credit_applied', 'deposit_applied', 'unapplied'], true)) {
            if ($isDeposit) {
                $totals['deposits_minor'] += $amount;
            } else {
                $totals['collected_minor'] += $kind === 'deposit_applied' ? $amount + $commission : $gross;
                $totals['commission_minor'] += $commission;
            }
        } elseif ($kind === 'collected_direct') {
            // The money is with the landlord, so it is not "collected" here: only what the agency earns on it moves their balance. A fee of the agency's own comes off as "other".
            $totals['commission_minor'] += $commission;
            $totals['other_minor'] += $amount + $commission;
        } elseif ($kind === 'payout') {
            $totals['payouts_minor'] += -$amount;
        } elseif ($kind === 'expense_landlord') {
            $totals['expenses_minor'] += -$amount;
        } else {
            $totals['other_minor'] += $amount;
        }
    }

    /**
     * What tenants paid each landlord directly in a period, net of reversals (LC-20): information beside the statement's figures, never in them.
     *
     * @return array<int, int> landlord id => amount
     */
    public static function directBy(int $companyId, string $from, string $to): array
    {
        return array_map('intval', DB::table('rental_allocations as a')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('a.company_id', $companyId)->where('p.method', RentalPayment::DIRECT)->whereBetween('a.applied_on', [$from, $to])
            ->groupBy('c.landlord_id')->selectRaw('c.landlord_id, SUM(a.amount_minor) AS total')->pluck('total', 'landlord_id')->all());
    }

    /** "Tenant paid you directly: 500,000. Commission 50,000." and the bill it settled. */
    private static function directWords(int $cid, int $gross, int $commission, int $fee, string $bill): string
    {
        $figure = fn (int $v) => (string) preg_replace('/^[^\d]+/', '', Minor::format($v, $cid));

        return 'Tenant paid you directly: '.$figure($gross).'.'.($commission !== 0 ? ' Commission '.$figure($commission).'.' : '').($fee !== 0 ? ' Fee for the agency '.$figure($fee).'.' : '').($bill !== '' ? ' '.$bill : '');
    }

    /**
     * What tenants paid this landlord directly in a period: each allocation, with its bill, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private static function directAllocations(int $cid, int $landlordId, string $from, string $to): \Illuminate\Support\Collection
    {
        return DB::table('rental_allocations as a')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('a.company_id', $cid)->where('c.landlord_id', $landlordId)->where('p.method', RentalPayment::DIRECT)->whereBetween('a.applied_on', [$from, $to])
            ->where('a.amount_minor', '!=', 0)->orderBy('a.applied_on')->orderBy('a.id')
            ->get(['a.applied_on', 'a.amount_minor', 'a.commission_minor', 'a.agency_minor', 'a.reverses_id', 'c.description', 'c.property_id', 'c.unit_id', 'c.tenant_id']);
    }

    /**
     * A direct payment the agency earned nothing on posts no journal row, so nothing above read it. The landlord is still told, on a line that moves nothing.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $paid
     * @param  array{property: array<int, string>, unit: array<int, string>, tenant: array<int, string>}  $names
     * @return list<array<string, mixed>>
     */
    private static function withSilentDirect(array $lines, \Illuminate\Support\Collection $paid, int $cid, array $names): array
    {
        $rows = $paid->filter(fn ($a) => (int) $a->commission_minor + (int) $a->agency_minor === 0)->values();
        if ($rows->isEmpty()) {
            return $lines;
        }
        $more = DB::table('rental_properties')->where('company_id', $cid)->whereIn('id', $rows->pluck('property_id')->unique()->all())->pluck('name', 'id')->all();
        $units = DB::table('rental_units')->where('company_id', $cid)->whereIn('id', $rows->pluck('unit_id')->unique()->all())->pluck('code', 'id')->all();
        $tenants = DB::table('rental_tenants')->where('company_id', $cid)->whereIn('id', $rows->pluck('tenant_id')->unique()->all())->pluck('name', 'id')->all();
        foreach ($rows as $r) {
            $back = $r->reverses_id !== null;
            $lines[] = ['date' => substr((string) $r->applied_on, 0, 10), 'type' => $back ? 'reversal' : 'collected_direct',
                'description' => ($back ? 'Correction: ' : '').self::directWords($cid, abs((int) $r->amount_minor), 0, 0, (string) $r->description),
                'property' => $names['property'][$r->property_id] ?? $more[$r->property_id] ?? null, 'unit' => $names['unit'][$r->unit_id] ?? $units[$r->unit_id] ?? null,
                'tenant' => $names['tenant'][$r->tenant_id] ?? $tenants[$r->tenant_id] ?? null, 'gross_minor' => 0, 'commission_minor' => 0, 'amount_minor' => 0, 'direct_minor' => (int) $r->amount_minor];
        }
        usort($lines, fn (array $a, array $b) => strcmp((string) $a['date'], (string) $b['date'])); // a stable sort: each day's lines stay in their order

        return $lines;
    }

    /**
     * What marks late money for the month it belongs to, in the note of its payment or payout (D7), by id. A note may say more around it.
     *
     * @return array{payment: array<int, string>, payout: array<int, string>}
     */
    private static function lateMarks(int $cid, $rows): array
    {
        $read = function (string $table, string $col) use ($cid, $rows): array {
            $ids = $rows->pluck($col)->filter()->unique()->values()->all();
            if ($ids === []) {
                return [];
            }
            $out = [];
            foreach (DB::table($table)->where('company_id', $cid)->whereIn('id', $ids)->where('notes', 'like', '%For %')->pluck('notes', 'id') as $id => $notes) {
                if (preg_match('/\bFor (?:January|February|March|April|May|June|July|August|September|October|November|December)(?: \d{4})?\b/', (string) $notes, $m)) {
                    $out[(int) $id] = $m[0];
                }
            }

            return $out;
        };

        return ['payment' => $read('rental_payments', 'payment_id'), 'payout' => $read('rental_payouts', 'payout_id')];
    }

    /** @param array{payment: array<int, string>, payout: array<int, string>} $marks */
    private static function markOf(array $marks, RentalEntry $row): string
    {
        $mark = $marks['payment'][(int) $row->payment_id] ?? $marks['payout'][(int) $row->payout_id] ?? null;

        return $mark === null ? '' : ' · '.$mark;
    }

    /**
     * Units of the landlord's active properties and how many had a tenant: today, or on $asOf (from the leases' own
     * dates and who owned the property that day, so a statement for a closed period reads the same whenever it is printed,
     * even after a handover, LC-01). A room the landlord or caretaker uses (LC-21) is neither let nor vacant: it is counted on its own. $owned: Handover::propertyIdsOn for $asOf, when the caller has it.
     *
     * @param  list<int>|null  $owned
     */
    public static function occupancy(RentalLandlord $landlord, ?string $asOf = null, ?array $owned = null): array
    {
        $cid = (int) $landlord->company_id;
        $props = DB::table('rental_properties')->where('company_id', $cid)->where('status', 'active')
            ->when($asOf === null, fn ($q) => $q->where('landlord_id', $landlord->id), fn ($q) => $q->whereIn('id', ($owned ?? Handover::propertyIdsOn($cid, (int) $landlord->id, (string) $asOf)) ?: [0]))->pluck('id');
        $unitRows = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $props->all() ?: [0])->where('status', '!=', 'inactive');
        $leaseRows = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $props->all() ?: [0]);
        if ($asOf === null) {
            $leaseRows->whereIn('status', RentalLease::LIVE);
        } else {
            $until = Carbon::parse($asOf, LocalDate::timezone($cid))->endOfDay()->utc();
            $unitRows->where('created_at', '<=', $until);
            $leaseRows->where('status', '!=', 'cancelled')->where('start_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>=', $asOf));
        }
        $counted = $unitRows->toBase()->selectRaw("COUNT(*) AS n, COALESCE(SUM(status = 'in_use'), 0) AS used")->first();
        $used = (int) $counted->used;
        $units = (int) $counted->n - $used;
        $occupied = $leaseRows->distinct()->count('unit_id');

        return ['units' => $units, 'occupied' => $occupied, 'vacant' => max(0, $units - $occupied)] + ($used > 0 ? ['in_use' => $used] : []);
    }

    /**
     * What tenants of the landlord's units owed on bills due by $asOf, as it stood that day: what had been allocated by
     * then, the bills not yet cancelled then, and the days late on that day. So a statement for a closed period does
     * not change when a tenant pays later (information for the landlord).
     */
    public static function arrears(RentalLandlord $landlord, string $asOf): array
    {
        $cid = (int) $landlord->company_id;
        $timezone = LocalDate::timezone($cid);
        $on = min($asOf, now($timezone)->toDateString());
        $until = Carbon::parse($asOf, $timezone)->endOfDay()->utc()->toDateTimeString();
        $rows = DB::table('rental_charges as c')->join('rental_units as u', 'u.id', '=', 'c.unit_id')->join('rental_tenants as t', 't.id', '=', 'c.tenant_id')
            ->where('c.company_id', $cid)->where('c.landlord_id', $landlord->id)->whereIn('c.goes_to', ['landlord'])->where('c.due_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('c.status', '!=', 'void')->orWhere('c.voided_at', '>', $until))
            ->selectRaw('c.lease_id, u.code AS unit, t.name AS tenant, c.due_date, c.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM rental_allocations a WHERE a.charge_id = c.id AND a.applied_on <= ?), 0) AS owed', [$asOf])
            ->get()->filter(fn ($r) => (int) $r->owed > 0);
        $out = [];
        foreach ($rows->groupBy('lease_id') as $lines) {
            $oldest = $lines->min('due_date');
            $out[] = ['unit' => $lines->first()->unit, 'tenant' => $lines->first()->tenant, 'owed_minor' => (int) $lines->sum('owed'),
                'days' => max(0, \App\Support\Rentals\Periods::days(Carbon::parse($oldest), Carbon::parse($on)))];
        }
        usort($out, fn ($a, $b) => [$b['owed_minor'], $a['unit']] <=> [$a['owed_minor'], $b['unit']]);

        return array_slice($out, 0, 200);
    }

    private static function names(int $cid, $rows): array
    {
        $ids = fn (string $col) => $rows->pluck($col)->filter()->unique()->values()->all() ?: [0];

        return [
            'property' => DB::table('rental_properties')->where('company_id', $cid)->whereIn('id', $ids('property_id'))->pluck('name', 'id')->all(),
            'unit' => DB::table('rental_units')->where('company_id', $cid)->whereIn('id', $ids('unit_id'))->pluck('code', 'id')->all(),
            'tenant' => DB::table('rental_tenants')->where('company_id', $cid)->whereIn('id', $ids('tenant_id'))->pluck('name', 'id')->all(),
        ];
    }
}

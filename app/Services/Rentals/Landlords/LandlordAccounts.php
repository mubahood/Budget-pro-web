<?php

namespace App\Services\Rentals\Landlords;

use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalUnit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
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
        'deposit_refund_landlord' => 'Deposit refunded for you', 'opening_landlord' => 'Opening balance', 'reversal' => 'Correction',
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
     * The statement for a period (04 §9.1 Statement).
     *
     * @return array<string, mixed>
     */
    public static function statement(RentalLandlord $landlord, string $from, string $to): array
    {
        $cid = (int) $landlord->company_id;
        $opening = self::balance($landlord, Carbon::parse($from)->subDay()->toDateString());
        $rows = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $landlord->id)
            ->whereBetween('occurred_on', [$from, $to])->where('landlord_minor', '!=', 0)->orderBy('occurred_on')->orderBy('id')->get();
        $allocs = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $rows->pluck('allocation_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $originals = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $rows->pluck('reverses_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $origAllocs = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $originals->pluck('allocation_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $names = self::names($cid, $rows);
        $issued = \App\Services\Rentals\Payments\Receipt::hasSnapshots() ? \App\Models\RentalPayment::withoutGlobalScopes()->where('company_id', $cid)
            ->whereIn('id', $rows->pluck('payment_id')->filter()->unique()->all() ?: [0])->get(['id', 'receipt_snapshot'])->keyBy('id') : collect();
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
            if (in_array($kind, ['collected', 'credit_applied', 'deposit_applied', 'unapplied'], true)) {
                $isDeposit = $alloc !== null && (int) $alloc->landlord_minor > 0 && (int) $alloc->commission_minor === 0
                    && RentalCharge::withoutGlobalScopes()->whereKey($alloc->charge_id)->value('kind') === 'deposit';
                if ($isDeposit) {
                    $totals['deposits_minor'] += $amount;
                } else {
                    $totals['collected_minor'] += $kind === 'deposit_applied' ? $amount + $commission : $gross;
                    $totals['commission_minor'] += $commission;
                }
            } elseif ($kind === 'payout') {
                $totals['payouts_minor'] += -$amount;
            } elseif ($kind === 'expense_landlord') {
                $totals['expenses_minor'] += -$amount;
            } else {
                $totals['other_minor'] += $amount;
            }
            $as = $issued->get($r->payment_id)?->receipt_snapshot['names'] ?? [];
            $lines[] = [
                'date' => $r->occurred_on->toDateString(), 'type' => $r->type === 'reversal' ? 'reversal' : $kind,
                'description' => ($r->type === 'reversal' ? 'Correction: ' : '').($src->description ?: (self::LABELS[$kind] ?? $kind)),
                'property' => $as['property'] ?? $names['property'][$r->property_id] ?? null, 'unit' => $as['unit'] ?? $names['unit'][$r->unit_id] ?? null,
                'tenant' => $as['tenant'] ?? $names['tenant'][$r->tenant_id] ?? null, 'gross_minor' => $gross, 'commission_minor' => $commission, 'amount_minor' => $amount,
            ];
        }
        $closing = $opening + array_sum(array_column($lines, 'amount_minor'));

        return [
            'landlord' => ['id' => (int) $landlord->id, 'name' => (string) $landlord->name], 'from' => $from, 'to' => $to,
            'currency' => RentalsModule::settings($cid)->currency, 'opening_minor' => $opening, 'closing_minor' => $closing,
            'lines' => $lines, 'totals' => $totals, 'occupancy' => self::occupancy($landlord, $to), 'arrears' => self::arrears($landlord, $to),
        ];
    }

    /**
     * Units of the landlord's active properties and how many had a tenant: today, or on $asOf (from the leases' own
     * dates, so a statement for a closed period reads the same whenever it is printed).
     */
    public static function occupancy(RentalLandlord $landlord, ?string $asOf = null): array
    {
        $cid = (int) $landlord->company_id;
        $props = DB::table('rental_properties')->where('company_id', $cid)->where('landlord_id', $landlord->id)->where('status', 'active')->pluck('id');
        $unitRows = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $props->all() ?: [0])->where('status', '!=', 'inactive');
        $leaseRows = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', $props->all() ?: [0]);
        if ($asOf === null) {
            $leaseRows->whereIn('status', RentalLease::LIVE);
        } else {
            $until = Carbon::parse($asOf, LocalDate::timezone($cid))->endOfDay()->utc();
            $unitRows->where('created_at', '<=', $until);
            $leaseRows->where('status', '!=', 'cancelled')->where('start_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>=', $asOf));
        }
        $units = $unitRows->count();
        $occupied = $leaseRows->distinct()->count('unit_id');

        return ['units' => $units, 'occupied' => $occupied, 'vacant' => max(0, $units - $occupied)];
    }

    /**
     * What tenants of the landlord's units owed on bills due by $asOf, as it stood that day: what had been allocated by
     * then, the bills not yet cancelled then, and the days late on that day. So a statement for a closed period does
     * not change when a tenant pays later (information for the landlord).
     */
    public static function arrears(RentalLandlord $landlord, string $asOf): array
    {
        $cid = (int) $landlord->company_id;
        $today = LocalDate::today($cid);
        $on = min($asOf, $today->toDateString());
        $until = Carbon::parse($asOf, LocalDate::timezone($cid))->endOfDay()->utc()->toDateTimeString();
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

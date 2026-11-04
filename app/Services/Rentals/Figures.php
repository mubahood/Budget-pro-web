<?php

namespace App\Services\Rentals;

use App\Models\RentalFollowUp;
use App\Models\RentalLease;
use App\Models\RentalMaintenance;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\User;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The figures Estate Ledger's screens show beside the records (docs/estate-ledger/04-architecture.md §9.1): the overview, a
 * property's occupancy and arrears, a landlord's counts and balance, a tenant's totals. Read only; every amount is a
 * sum of the journal, the bills or the allocations, or of Leases::position, never worked out again by hand. A
 * caretaker's figures cover their properties only (RentalAccess::propertyIds).
 */
final class Figures
{
    /** How many lines of each kind the overview's attention list shows. */
    public const ATTENTION_EACH = 5;

    /**
     * The overview (§9.1 Overview). `today` holds the payment records received today (newest first), for the caller
     * to show as PaymentBrief.
     *
     * @return array<string, mixed>
     */
    public static function overview(User $by, ?Carbon $today = null): array
    {
        $cid = (int) $by->company_id;
        $today ??= LocalDate::today($cid);
        $s = RentalsModule::settings($cid);
        $ids = RentalAccess::propertyIds($by);
        $units = self::units($cid, $ids);
        $month = self::month($cid, $today, $ids);
        $totals = RentalLedger::totals($cid, $ids === null ? [] : ['property_id' => $ids ?: [0]]);
        $seesCash = $ids === null && (RentalAccess::can($by, 'rentals_expenses') || RentalAccess::can($by, 'rentals_reports'));
        $soon = $today->copy()->addDays(30)->toDateString();
        $ending = self::scoped(RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', RentalLease::LIVE), $ids)
            ->where(fn ($q) => $q->whereBetween('end_date', [$today->toDateString(), $soon])
                ->orWhere(fn ($q) => $q->where('status', 'notice')->whereBetween('move_out_on', [$today->toDateString(), $soon])));
        $arrears = Arrears::list($cid, $by, [], $today);
        $todayPayments = self::scoped(RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('method', RentalPayment::CASH_METHODS), $ids)
            ->where('paid_on', $today->toDateString())->orderByDesc('id')->limit(50)->get();

        return [
            'currency' => (string) $s->currency, 'mode' => (string) $s->mode,
            'units' => $units['units'], 'occupied' => $units['occupied'], 'vacant' => $units['vacant'], 'occupancy_pct' => $units['occupancy_pct'],
            'month' => $month,
            'arrears' => Arrears::aging($cid, $by, $today),
            'credit_minor' => (int) $totals['credit_minor'], 'deposits_minor' => (int) $totals['deposit_minor'], 'landlords_minor' => (int) $totals['landlord_minor'],
            'maintenance_open' => self::scoped(RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', ['open', 'in_progress']), $ids)->count(),
            'ending_30' => (clone $ending)->count(),
            'cash' => $seesCash ? RentalLedger::cash($cid) : null,
            'attention' => self::attention($cid, $ids, $today, $arrears, (clone $ending)),
            'today' => $todayPayments->values()->all(),
        ];
    }

    /** Units (not switched off, in active properties), occupied (a live lease), vacant and the occupancy rate. @param list<int>|null $ids */
    public static function units(int $companyId, ?array $ids): array
    {
        $units = DB::table('rental_units as u')->join('rental_properties as p', 'p.id', '=', 'u.property_id')->where('u.company_id', $companyId)
            ->where('u.status', '!=', 'inactive')->where('p.status', 'active');
        if ($ids !== null) {
            $units->whereIn('u.property_id', $ids ?: [0]);
        }
        $count = (int) $units->count();
        $occupied = (int) self::scoped(RentalLease::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', RentalLease::LIVE), $ids)->distinct()->count('unit_id');

        return ['units' => $count, 'occupied' => $occupied, 'vacant' => max(0, $count - $occupied), 'occupancy_pct' => self::pct($occupied, $count)];
    }

    /**
     * This month: billed (bills due in the month, not cancelled; refunds are not bills) and collected (the parts of
     * cash, bank, mobile money and cheque payments that settled bills in the month, net of reversals).
     *
     * @param  list<int>|null  $ids
     * @return array{billed_minor: int, collected_minor: int, rate_pct: float}
     */
    public static function month(int $companyId, Carbon $today, ?array $ids): array
    {
        [$from, $to] = [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()];
        $billed = DB::table('rental_charges')->where('company_id', $companyId)->whereBetween('due_date', [$from, $to])->where('status', '!=', 'void')->where('kind', '!=', 'refund');
        $collected = DB::table('rental_allocations as a')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('a.company_id', $companyId)->whereIn('p.method', RentalPayment::CASH_METHODS)->where('c.kind', '!=', 'refund')->whereBetween('a.applied_on', [$from, $to]);
        if ($ids !== null) {
            $billed->whereIn('property_id', $ids ?: [0]);
            $collected->whereIn('c.property_id', $ids ?: [0]);
        }
        $b = (int) $billed->sum('amount_minor');
        $c = (int) $collected->sum('a.amount_minor');

        return ['billed_minor' => $b, 'collected_minor' => $c, 'rate_pct' => self::pct($c, $b)];
    }

    /**
     * Per property: units, occupied, vacant, occupancy and arrears (overdue past the grace days).
     *
     * @param  list<int>  $propertyIds
     * @return array<int, array{units_count: int, occupied_count: int, vacant_count: int, occupancy_pct: float, arrears_minor: int}>
     */
    public static function properties(int $companyId, array $propertyIds, ?User $by = null): array
    {
        if ($propertyIds === []) {
            return [];
        }
        $units = DB::table('rental_units')->where('company_id', $companyId)->whereIn('property_id', $propertyIds)->where('status', '!=', 'inactive')
            ->groupBy('property_id')->selectRaw('property_id, COUNT(*) AS n')->pluck('n', 'property_id');
        $occupied = DB::table('rental_leases')->where('company_id', $companyId)->whereIn('property_id', $propertyIds)->whereIn('status', RentalLease::LIVE)
            ->groupBy('property_id')->selectRaw('property_id, COUNT(DISTINCT unit_id) AS n')->pluck('n', 'property_id');
        $arrears = [];
        foreach (Arrears::list($companyId, $by) as $row) {
            $pid = (int) $row['property']['id'];
            $arrears[$pid] = ($arrears[$pid] ?? 0) + (int) $row['overdue_minor'];
        }
        $out = [];
        foreach ($propertyIds as $id) {
            $u = (int) ($units[$id] ?? 0);
            $o = (int) ($occupied[$id] ?? 0);
            $out[(int) $id] = ['units_count' => $u, 'occupied_count' => $o, 'vacant_count' => max(0, $u - $o), 'occupancy_pct' => self::pct($o, $u), 'arrears_minor' => $arrears[$id] ?? 0];
        }

        return $out;
    }

    /**
     * Per landlord: active properties, units (not switched off) and the balance owed to them.
     *
     * @param  list<int>  $landlordIds
     * @return array<int, array{properties_count: int, units_count: int, balance_minor: int}>
     */
    public static function landlords(int $companyId, array $landlordIds): array
    {
        if ($landlordIds === []) {
            return [];
        }
        $props = DB::table('rental_properties')->where('company_id', $companyId)->whereIn('landlord_id', $landlordIds)->where('status', 'active')
            ->groupBy('landlord_id')->selectRaw('landlord_id, COUNT(*) AS n')->pluck('n', 'landlord_id');
        $units = DB::table('rental_units as u')->join('rental_properties as p', 'p.id', '=', 'u.property_id')->where('u.company_id', $companyId)
            ->whereIn('p.landlord_id', $landlordIds)->where('p.status', 'active')->where('u.status', '!=', 'inactive')
            ->groupBy('p.landlord_id')->selectRaw('p.landlord_id AS landlord_id, COUNT(*) AS n')->pluck('n', 'landlord_id');
        $balances = LandlordAccounts::balances($companyId);
        $out = [];
        foreach ($landlordIds as $id) {
            $out[(int) $id] = ['properties_count' => (int) ($props[$id] ?? 0), 'units_count' => (int) ($units[$id] ?? 0), 'balance_minor' => (int) ($balances[$id] ?? 0)];
        }

        return $out;
    }

    /**
     * A tenant's leases (newest first; a caretaker's properties only) with their positions, and what the tenant owes and
     * has paid ahead over them.
     *
     * @return array{owed_minor: int, credit_minor: int, leases: list<array{lease: RentalLease, position: array<string, mixed>}>}
     */
    public static function tenant(RentalTenant $tenant, ?User $by = null): array
    {
        $cid = (int) $tenant->company_id;
        $leases = self::scoped(RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('tenant_id', $tenant->id)->where('status', '!=', 'cancelled'),
            $by === null ? null : RentalAccess::propertyIds($by))->orderByDesc('start_date')->orderByDesc('id')->get();
        $today = LocalDate::today($cid);
        $rows = [];
        $owed = 0;
        $credit = 0;
        foreach ($leases as $l) {
            $pos = Leases::position($l, $today);
            $owed += (int) $pos['owed_minor'];
            $credit += (int) $pos['credit_minor'];
            $rows[] = ['lease' => $l, 'position' => $pos];
        }

        return ['owed_minor' => $owed, 'credit_minor' => $credit, 'leases' => $rows];
    }

    /** $part of $whole as a percentage with one decimal (0 when there is no whole). */
    public static function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
    }

    /**
     * What needs someone today: the biggest overdue leases, promises to pay today, urgent repairs, terms that ran out
     * with the tenant still there, leases ending in the next 30 days, and vacant units.
     *
     * @param  list<int>|null  $ids
     * @param  list<array<string, mixed>>  $arrears  Arrears::list()
     * @return list<array{kind: string, title: string, detail: string, link: array{type: string, id: int}}>
     */
    private static function attention(int $cid, ?array $ids, Carbon $today, array $arrears, $ending): array
    {
        $out = [];
        $where = fn (?string $unit, ?string $property) => trim(($unit ?? '').($property ? ' · '.$property : ''));
        foreach (array_slice($arrears, 0, self::ATTENTION_EACH) as $r) {
            $out[] = ['kind' => 'overdue', 'title' => Minor::format((int) $r['overdue_minor'], $cid).' overdue',
                'detail' => $r['tenant']['name'].', '.$where($r['unit']['code'], $r['property']['name']).' · '.$r['days'].' '.($r['days'] === 1 ? 'day' : 'days'),
                'link' => ['type' => 'lease', 'id' => (int) $r['lease_id']]];
        }
        $names = fn ($leases) => [
            'tenant' => DB::table('rental_tenants')->where('company_id', $cid)->whereIn('id', $leases->pluck('tenant_id')->all() ?: [0])->pluck('name', 'id'),
            'unit' => DB::table('rental_units')->where('company_id', $cid)->whereIn('id', $leases->pluck('unit_id')->all() ?: [0])->pluck('code', 'id'),
            'property' => DB::table('rental_properties')->where('company_id', $cid)->whereIn('id', $leases->pluck('property_id')->all() ?: [0])->pluck('name', 'id'),
        ];
        $promised = RentalFollowUp::withoutGlobalScopes()->where('company_id', $cid)->where('promise_on', $today->toDateString())
            ->whereIn('lease_id', self::scoped(RentalLease::withoutGlobalScopes()->where('company_id', $cid), $ids)->select('id'))
            ->orderByDesc('id')->get()->unique('lease_id')->take(self::ATTENTION_EACH);
        if ($promised->isNotEmpty()) {
            $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $promised->pluck('lease_id')->all())->get()->keyBy('id');
            $n = $names($leases);
            foreach ($promised as $f) {
                if (($l = $leases->get($f->lease_id)) === null) {
                    continue;
                }
                $out[] = ['kind' => 'promise', 'title' => 'Promised to pay today', 'detail' => ($n['tenant'][$l->tenant_id] ?? '').', '.$where($n['unit'][$l->unit_id] ?? null, $n['property'][$l->property_id] ?? null)
                    .' · owes '.Minor::format((int) Leases::position($l, $today)['owed_minor'], $cid), 'link' => ['type' => 'lease', 'id' => (int) $l->id]];
            }
        }
        $urgent = self::scoped(RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', ['open', 'in_progress'])->where('priority', 'urgent'), $ids)
            ->orderBy('id')->limit(self::ATTENTION_EACH)->get();
        if ($urgent->isNotEmpty()) {
            $props = DB::table('rental_properties')->where('company_id', $cid)->whereIn('id', $urgent->pluck('property_id')->all())->pluck('name', 'id');
            $units = DB::table('rental_units')->where('company_id', $cid)->whereIn('id', $urgent->pluck('unit_id')->filter()->all() ?: [0])->pluck('code', 'id');
            foreach ($urgent as $m) {
                $out[] = ['kind' => 'urgent', 'title' => 'Urgent: '.$m->title, 'detail' => trim($m->number.' · '.$where($units[$m->unit_id] ?? null, $props[$m->property_id] ?? null), ' ·'),
                    'link' => ['type' => 'maintenance', 'id' => (int) $m->id]];
            }
        }
        $ran = self::scoped(RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', RentalLease::LIVE), $ids)
            ->whereNotNull('end_date')->where('end_date', '<', $today->toDateString())->orderBy('end_date')->limit(50)->get()
            ->filter(fn (RentalLease $l) => Leases::termEnded($l, $today))->take(self::ATTENTION_EACH);
        if ($ran->isNotEmpty()) {
            $n = $names($ran);
            foreach ($ran as $l) {
                $out[] = ['kind' => 'ending', 'title' => 'Term ended', 'detail' => ($n['tenant'][$l->tenant_id] ?? '').', '.$where($n['unit'][$l->unit_id] ?? null, $n['property'][$l->property_id] ?? null)
                    .' · ended '.$l->end_date->format('j M Y').'. Renew or move out', 'link' => ['type' => 'lease', 'id' => (int) $l->id]];
            }
        }
        $soon = $ending->orderByRaw('COALESCE(move_out_on, end_date)')->limit(self::ATTENTION_EACH)->get();
        if ($soon->isNotEmpty()) {
            $n = $names($soon);
            foreach ($soon as $l) {
                $moving = $l->status === 'notice' && $l->move_out_on !== null && ($l->end_date === null || $l->move_out_on->lte($l->end_date));
                $day = $moving ? $l->move_out_on : $l->end_date;
                $out[] = ['kind' => 'ending', 'title' => ($moving ? 'Moving out ' : 'Lease ends ').$day->format('j M Y'),
                    'detail' => ($n['tenant'][$l->tenant_id] ?? '').', '.$where($n['unit'][$l->unit_id] ?? null, $n['property'][$l->property_id] ?? null), 'link' => ['type' => 'lease', 'id' => (int) $l->id]];
            }
        }
        $vacant = DB::table('rental_units as u')->join('rental_properties as p', 'p.id', '=', 'u.property_id')
            ->where('u.company_id', $cid)->where('u.status', 'available')->where('p.status', 'active')
            ->whereNotExists(fn ($q) => $q->from('rental_leases as l')->whereColumn('l.unit_id', 'u.id')->whereIn('l.status', RentalLease::LIVE))
            ->when($ids !== null, fn ($q) => $q->whereIn('u.property_id', $ids ?: [0]))
            ->orderBy('p.name')->orderBy('u.code')->limit(self::ATTENTION_EACH)->get(['u.id', 'u.code', 'p.name as property_name']);
        foreach ($vacant as $u) {
            $out[] = ['kind' => 'vacant', 'title' => 'Vacant', 'detail' => $where($u->code, $u->property_name), 'link' => ['type' => 'unit', 'id' => (int) $u->id]];
        }

        return $out;
    }

    /**
     * Narrow a query on a table with property_id to a caretaker's properties (null: all).
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $q
     * @param  list<int>|null  $ids
     * @return T
     */
    private static function scoped($q, ?array $ids)
    {
        return $ids === null ? $q : $q->whereIn('property_id', $ids ?: [0]);
    }
}

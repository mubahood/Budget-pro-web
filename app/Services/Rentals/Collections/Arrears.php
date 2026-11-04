<?php

namespace App\Services\Rentals\Collections;

use App\Models\User;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Periods;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arrears (docs/estate-ledger/04-architecture.md §17): bills still open after their due date plus the grace days, aged
 * 0–30, 31–60, 61–90 and 90+ days by how long each bill has been overdue, per lease, with the last follow-up and the
 * tenant's promise. Read only; a caretaker sees their properties.
 */
final class Arrears
{
    public const BUCKETS = ['d0_30' => [0, 30], 'd31_60' => [31, 60], 'd61_90' => [61, 90], 'd90_plus' => [91, null]];

    /** @return array{total_minor: int, leases: int, aging: array<string, int>} */
    public static function aging(int $companyId, ?User $by = null, ?Carbon $today = null): array
    {
        $out = ['total_minor' => 0, 'leases' => 0, 'aging' => array_fill_keys(array_keys(self::BUCKETS), 0)];
        $leases = [];
        foreach (self::overdueCharges($companyId, $by, $today) as $c) {
            $out['total_minor'] += $c->left;
            $out['aging'][self::bucket($c->days)] += $c->left;
            $leases[$c->lease_id] = true;
        }
        $out['leases'] = count($leases);

        return $out;
    }

    /**
     * Overdue leases, biggest first: lease, tenant, unit, property, owed overdue, days (oldest bill), last follow-up and
     * promise date.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(int $companyId, ?User $by = null, array $filters = [], ?Carbon $today = null): array
    {
        $rows = [];
        foreach (self::overdueCharges($companyId, $by, $today, $filters['property_id'] ?? null) as $c) {
            $r = $rows[$c->lease_id] ?? ['lease_id' => (int) $c->lease_id, 'overdue_minor' => 0, 'days' => 0];
            $r['overdue_minor'] += $c->left;
            $r['days'] = max($r['days'], $c->days);
            $rows[$c->lease_id] = $r;
        }
        if ($rows === []) {
            return [];
        }
        $ids = array_keys($rows);
        $meta = DB::table('rental_leases as l')->join('rental_tenants as t', 't.id', '=', 'l.tenant_id')->join('rental_units as u', 'u.id', '=', 'l.unit_id')
            ->join('rental_properties as p', 'p.id', '=', 'l.property_id')->where('l.company_id', $companyId)->whereIn('l.id', $ids)
            ->get(['l.id', 'l.number', 'l.status', 'l.tenant_id', 't.name as tenant', 't.phone', 't.phone_e164', 'l.unit_id', 'u.code as unit', 'l.property_id', 'p.name as property'])->keyBy('id');
        $last = DB::table('rental_follow_ups as f')->where('f.company_id', $companyId)->whereIn('f.lease_id', $ids)
            ->whereRaw('f.id = (SELECT MAX(f2.id) FROM rental_follow_ups f2 WHERE f2.lease_id = f.lease_id)')
            ->get(['f.lease_id', 'f.channel', 'f.note', 'f.promise_on', 'f.created_at'])->keyBy('lease_id');
        $min = $filters['min_days'] ?? null;
        $out = [];
        foreach ($rows as $id => $r) {
            $m = $meta->get($id);
            if ($m === null || ($min !== null && $r['days'] < (int) $min)) {
                continue;
            }
            $f = $last->get($id);
            $out[] = $r + ['bucket' => self::bucket($r['days']), 'number' => $m->number, 'status' => $m->status, 'tenant' => ['id' => (int) $m->tenant_id, 'name' => $m->tenant, 'phone' => $m->phone, 'phone_e164' => $m->phone_e164],
                'unit' => ['id' => (int) $m->unit_id, 'code' => $m->unit], 'property' => ['id' => (int) $m->property_id, 'name' => $m->property],
                'last_follow_up' => $f ? ['channel' => $f->channel, 'note' => $f->note, 'promise_on' => $f->promise_on, 'at' => $f->created_at] : null];
        }
        $sort = $filters['sort'] ?? 'amount';
        usort($out, fn ($a, $b) => $sort === 'days' ? [$b['days'], $b['overdue_minor']] <=> [$a['days'], $a['overdue_minor']] : [$b['overdue_minor'], $b['days']] <=> [$a['overdue_minor'], $a['days']]);

        return $out;
    }

    /**
     * What each overdue lease owes in each age bucket (the arrears report): lease id => the four buckets, the lease's
     * total overdue and the days its oldest overdue bill is late. The buckets of every lease add up to aging()'s.
     *
     * @return array<int, array{d0_30: int, d31_60: int, d61_90: int, d90_plus: int, total_minor: int, days: int}>
     */
    public static function byLease(int $companyId, ?User $by = null, ?int $propertyId = null, ?Carbon $today = null): array
    {
        $out = [];
        foreach (self::overdueCharges($companyId, $by, $today, $propertyId) as $c) {
            $r = &$out[$c->lease_id];
            $r ??= array_fill_keys(array_keys(self::BUCKETS), 0) + ['total_minor' => 0, 'days' => 0];
            $r[self::bucket($c->days)] += $c->left;
            $r['total_minor'] += $c->left;
            $r['days'] = max($r['days'], $c->days);
            unset($r);
        }

        return $out;
    }

    public static function bucket(int $days): string
    {
        foreach (self::BUCKETS as $k => [$lo, $hi]) {
            if ($days >= $lo && ($hi === null || $days <= $hi)) {
                return $k;
            }
        }

        return 'd0_30';
    }

    /** Open bills past due + grace, each with what is left and how many days it is overdue. */
    private static function overdueCharges(int $companyId, ?User $by, ?Carbon $today, ?int $propertyId = null): array
    {
        $today ??= LocalDate::today($companyId);
        $grace = (int) RentalsModule::settings($companyId)->grace_days;
        $q = DB::table('rental_charges')->where('company_id', $companyId)->where('status', 'open')->where('kind', '!=', 'refund')
            ->where('due_date', '<', $today->copy()->subDays($grace)->toDateString());
        if ($propertyId !== null) {
            $q->where('property_id', $propertyId);
        }
        RentalAccess::scope($q, $by);
        $out = [];
        foreach ($q->get(['lease_id', 'due_date', 'amount_minor', 'paid_minor']) as $c) {
            $out[] = (object) ['lease_id' => (int) $c->lease_id, 'left' => (int) $c->amount_minor - (int) $c->paid_minor,
                'days' => max(0, Periods::days(Carbon::parse($c->due_date), $today))];
        }

        return $out;
    }
}

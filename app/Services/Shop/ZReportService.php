<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Shift;
use App\Models\ZReport;
use App\Support\LocalDate;
use App\Support\LocalTime;
use App\Support\SalesSource;
use App\Support\StoreFeatures;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * X and Z reports (supermarket plan E2).
 *  - X: the figures of one shift, or of one shop day, at any moment. Read-only, changes nothing.
 *  - Z: closes a shop day (per location when the shop uses more than one): the same figures, stored
 *    once in `z_reports` with a gap-free number (NumberSequencer 'z' → Z-2026-000001), immutable.
 *
 * Sales are never refused after a Z: the phone app syncs late. A sale that lands in an already-closed
 * day is listed on the next X/Z as a late sale for that day.
 */
class ZReportService
{
    /** More than one active location: a Z closes one location's day. */
    public function locationsUsed(int $companyId): bool
    {
        return DB::table('locations')->where('company_id', $companyId)->where('is_active', 1)->count() > 1;
    }

    /** 0 (the whole shop) unless the shop uses locations; then the location asked for, or the default one. */
    public function locationKey(int $companyId, ?int $locationId): int
    {
        if (! $this->locationsUsed($companyId)) {
            return 0;
        }
        if ($locationId) {
            LocationStock::assertLocation($companyId, $locationId);

            return $locationId;
        }

        return LocationStock::defaultLocation($companyId);
    }

    public function closed(int $companyId, string $date, ?int $locationId = null): ?ZReport
    {
        return ZReport::withoutGlobalScopes()->where('company_id', $companyId)->where('location_key', $this->locationKey($companyId, $locationId))
            ->whereDate('business_date', $date)->first();
    }

    /**
     * Close a shop day. Refused for a day still to come, or a day already closed.
     */
    public function close(int $companyId, string $date, int $userId, ?int $locationId = null): ZReport
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        if (! StoreFeatures::enabled($company, 'cash_control')) {
            throw BusinessRuleException::make('feature_off', 'X and Z reports are off for this shop. Switch on cash control in Business settings.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > LocalDate::today($companyId)->toDateString()) {
            throw BusinessRuleException::make('invalid_date', 'Choose today or a past day to close.');
        }
        $key = $this->locationKey($companyId, $locationId);
        $done = fn () => BusinessRuleException::make('day_closed', 'This day is already closed'.(($z = $this->closed($companyId, $date, $locationId)) ? ' (Z report '.$z->number.').' : '.'));

        try {
            return DB::transaction(function () use ($companyId, $date, $userId, $locationId, $key, $done) {
                if (ZReport::withoutGlobalScopes()->where('company_id', $companyId)->where('location_key', $key)->whereDate('business_date', $date)->lockForUpdate()->exists()) {
                    throw $done();
                }
                $totals = $this->figures($companyId, $date, $locationId);
                $z = new ZReport();
                $z->forceFill([
                    'company_id' => $companyId,
                    'location_key' => $key,
                    'business_date' => $date,
                    // Taken inside this transaction: a failed close rolls the number back too (no gaps).
                    'number' => NumberSequencer::next($companyId, 'z', Carbon::parse($date)),
                    'closed_by' => $userId,
                ]);
                $totals['number'] = $z->number;
                $z->totals = $totals;
                $z->save();

                return $z;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'z_reports_day_unique')) {
                throw $done(); // closed a moment ago by someone else
            }
            throw $e;
        }
    }

    /**
     * Every figure of an X/Z report: one shop day (optionally one location), or one shift when $shift is given.
     *
     * @return array<string, mixed>
     */
    public function figures(int $companyId, string $date, ?int $locationId = null, ?Shift $shift = null): array
    {
        LocalTime::prime($companyId);
        $company = Company::withoutGlobalScopes()->find($companyId);
        $tz = LocalDate::timezone($companyId);
        $from = Carbon::parse($date, $tz)->startOfDay()->utc();
        $to = Carbon::parse($date, $tz)->endOfDay()->utc();
        $key = $shift ? 0 : $this->locationKey($companyId, $locationId);
        $default = $key > 0 ? LocationStock::defaultLocation($companyId) : 0;
        $saleLoc = fn (string $a) => "COALESCE((SELECT MIN(lr.location_id) FROM stock_records lr WHERE lr.sale_record_id = {$a}.id),"
            ." (SELECT d.location_id FROM devices d WHERE d.company_id = {$a}.company_id AND d.device_id = {$a}.device_id LIMIT 1), {$default})";
        $shiftLoc = fn (string $a) => "COALESCE((SELECT d.location_id FROM devices d WHERE d.company_id = {$a}.company_id AND d.device_id = {$a}.device_id LIMIT 1), {$default})";
        $atLoc = fn ($q, string $expr) => $key > 0 ? $q->whereRaw("{$expr} = ?", [$key]) : $q;

        // ── Sales of the day (or of the shift) ──
        $day = SalesSource::localDay('s.sale_date', 's.created_at');
        $sales = DB::table('sale_records as s')->where('s.company_id', $companyId)->where('s.is_deleted', 0);
        if ($shift) {
            $sales->where('s.shift_id', $shift->id);
        } else {
            [$win, $bind] = SalesSource::window('s.sale_date', 's.created_at', $date, $date);
            $sales->whereRaw("{$day} = ?".$win, [$date, ...$bind]);
            $atLoc($sales, $saleLoc('s'));
        }
        $live = (clone $sales)->whereNull('s.voided_at')->where('s.status', '<>', 'Voided');
        $s = (clone $live)->selectRaw('COUNT(*) AS n, COALESCE(SUM(s.total_amount), 0) AS gross, COALESCE(SUM(s.refunded_amount), 0) AS refunded,
            COALESCE(SUM(s.discount_amount), 0) AS disc, SUM(CASE WHEN s.discount_amount > 0 THEN 1 ELSE 0 END) AS disc_n')->first();
        $net = round((float) $s->gross - (float) $s->refunded, 2);
        $byCashierSales = (clone $live)->groupBy('s.created_by_id')->selectRaw('s.created_by_id AS uid, COUNT(*) AS n, COALESCE(SUM(s.total_amount - s.refunded_amount), 0) AS total')
            ->get()->keyBy('uid');

        // ── Voids: voided in the day (or sales of the shift that were voided) ──
        $voids = DB::table('sale_records as s')->where('s.company_id', $companyId)->where('s.is_deleted', 0)->whereNotNull('s.voided_at');
        $shift ? $voids->where('s.shift_id', $shift->id) : $atLoc($voids->whereBetween('s.voided_at', [$from, $to]), $saleLoc('s'));
        $v = $voids->selectRaw('COUNT(*) AS n, COALESCE(SUM(s.total_amount), 0) AS total')->first();

        // ── Returns / refunds ──
        $ret = DB::table('sale_returns as r')->join('sale_records as s', 's.id', '=', 'r.sale_record_id')->where('r.company_id', $companyId)->where('r.is_deleted', 0);
        $shift ? $ret->where('r.shift_id', $shift->id) : $atLoc($ret->whereBetween('r.created_at', [$from, $to]), $saleLoc('s'));
        $r = $ret->selectRaw('COUNT(*) AS n, COALESCE(SUM(r.value), 0) AS value, COALESCE(SUM(r.refund_amount), 0) AS refunded')->first();

        // ── Tenders: money received (refunds out are negative) ──
        $pay = DB::table('payments as p')->leftJoin('sale_records as s', 's.id', '=', 'p.sale_record_id')->where('p.company_id', $companyId)->where('p.is_deleted', 0);
        if ($shift) {
            $pay->where('p.shift_id', $shift->id);
        } else {
            $pay->whereBetween('p.received_at', [$from, $to]);
            if ($key > 0) {
                $pay->where(fn ($w) => $w->where(fn ($x) => $x->whereNotNull('p.sale_record_id')->whereRaw($saleLoc('s').' = ?', [$key]))
                    ->when($key === $default, fn ($x) => $x->orWhereNull('p.sale_record_id')));
            }
        }
        $byMethod = $pay->groupBy('p.method')->selectRaw('p.method, COUNT(*) AS n, COALESCE(SUM(p.amount), 0) AS total')->orderBy('p.method')->get()
            ->mapWithKeys(fn ($x) => [(string) $x->method => ['count' => (int) $x->n, 'total' => round((float) $x->total, 2)]])->all();

        // ── Shifts: over/short per cashier (closed in the day), cash movements, no-sales ──
        $shifts = DB::table('shifts as sh')->where('sh.company_id', $companyId)->where('sh.is_deleted', 0);
        if ($shift) {
            $shifts->where('sh.id', $shift->id);
        } else {
            $atLoc($shifts, $shiftLoc('sh'));
        }
        $closedShifts = (clone $shifts)->where('sh.status', 'closed')->when(! $shift, fn ($q) => $q->whereBetween('sh.closed_at', [$from, $to]))
            ->groupBy('sh.opened_by_id')->selectRaw('sh.opened_by_id AS uid, COUNT(*) AS n, COALESCE(SUM(sh.expected_cash), 0) AS expected,
                COALESCE(SUM(sh.counted_cash), 0) AS counted, COALESCE(SUM(sh.variance), 0) AS variance')->get()->keyBy('uid');
        $openShifts = $shift ? 0 : (clone $shifts)->where('sh.status', 'open')->count();

        $mv = DB::table('cash_movements as m')->join('shifts as sh', 'sh.id', '=', 'm.shift_id')->where('m.company_id', $companyId);
        if ($shift) {
            $mv->where('m.shift_id', $shift->id);
        } else {
            $atLoc($mv->whereBetween('m.created_at', [$from, $to]), $shiftLoc('sh'));
        }
        $movements = [];
        foreach (CashMovement::TYPES as $type => [$label]) {
            $movements[$type] = ['label' => $label, 'count' => 0, 'total' => 0.0];
        }
        foreach ((clone $mv)->groupBy('m.type')->selectRaw('m.type, COUNT(*) AS n, COALESCE(SUM(m.amount), 0) AS total')->get() as $x) {
            if (isset($movements[$x->type])) {
                $movements[$x->type]['count'] = (int) $x->n;
                $movements[$x->type]['total'] = round((float) $x->total, 2);
            }
        }
        $noSales = (clone $mv)->where('m.type', 'no_sale')->groupBy('m.created_by')->selectRaw('m.created_by AS uid, COUNT(*) AS n')->pluck('n', 'uid');

        // ── People ──
        $uids = collect([...$byCashierSales->keys(), ...$closedShifts->keys(), ...$noSales->keys()])->filter()->unique()->values();
        $names = DB::table('admin_users')->whereIn('id', $uids)->pluck('name', 'id');
        $cashiers = $uids->map(fn ($uid) => [
            'user_id' => (int) $uid,
            'name' => (string) ($names[$uid] ?? 'Someone'),
            'sales_count' => (int) ($byCashierSales[$uid]->n ?? 0),
            'sales_total' => round((float) ($byCashierSales[$uid]->total ?? 0), 2),
            'shifts' => (int) ($closedShifts[$uid]->n ?? 0),
            'expected' => round((float) ($closedShifts[$uid]->expected ?? 0), 2),
            'counted' => round((float) ($closedShifts[$uid]->counted ?? 0), 2),
            'over_short' => round((float) ($closedShifts[$uid]->variance ?? 0), 2),
            'no_sales' => (int) ($noSales[$uid] ?? 0),
        ])->sortBy('name')->values()->all();

        $rate = (float) ($company?->tax_rate ?? 0);

        return [
            'scope' => $shift ? 'shift' : 'day',
            'date' => $date,
            'location_id' => $key ?: null,
            'location_name' => $key ? DB::table('locations')->where('id', $key)->value('name') : null,
            'shift_id' => $shift?->id,
            'shift_number' => $shift?->number,
            'currency' => $company?->currency,
            'generated_at' => now()->toIso8601String(),
            'sales' => ['count' => (int) $s->n, 'gross' => round((float) $s->gross, 2), 'refunded' => round((float) $s->refunded, 2), 'net' => $net],
            'by_method' => $byMethod,
            'tax' => ['rate' => $rate, 'net_sales' => $net, 'vat' => $rate > 0 ? round($net * $rate / (100 + $rate), 2) : 0.0],
            'discounts' => ['count' => (int) $s->disc_n, 'total' => round((float) $s->disc, 2)],
            'voids' => ['count' => (int) $v->n, 'total' => round((float) $v->total, 2)],
            'refunds' => ['count' => (int) $r->n, 'value' => round((float) $r->value, 2), 'refunded' => round((float) $r->refunded, 2)],
            'cash_movements' => $movements,
            'no_sales' => (int) $noSales->sum(),
            'cashiers' => $cashiers,
            'over_short' => round((float) $closedShifts->sum('variance'), 2),
            'open_shifts' => $openShifts,
            'late_sales' => $shift ? [] : $this->lateSales($companyId, $key, $saleLoc),
        ];
    }

    /**
     * Sales that arrived (created) after their day was closed, and after the last Z of this shop/location:
     * each one is listed once, on the next X/Z.
     *
     * @return list<array{date: string, count: int, total: float}>
     */
    private function lateSales(int $companyId, int $key, \Closure $saleLoc): array
    {
        $since = ZReport::withoutGlobalScopes()->where('company_id', $companyId)->where('location_key', $key)->max('created_at');
        if ($since === null) {
            return [];
        }
        $day = SalesSource::localDay('s.sale_date', 's.created_at');
        $q = DB::table('sale_records as s')
            ->join('z_reports as z', fn ($j) => $j->on('z.company_id', '=', 's.company_id')->where('z.location_key', '=', $key)->whereRaw("z.business_date = {$day}"))
            ->where('s.company_id', $companyId)->where('s.is_deleted', 0)->whereNull('s.voided_at')->where('s.status', '<>', 'Voided')
            ->whereColumn('s.created_at', '>', 'z.created_at')->where('s.created_at', '>', $since);
        if ($key > 0) {
            $q->whereRaw($saleLoc('s').' = ?', [$key]);
        }

        return $q->groupBy('z.business_date')->orderBy('z.business_date')
            ->selectRaw('z.business_date AS d, COUNT(*) AS n, COALESCE(SUM(s.total_amount - s.refunded_amount), 0) AS total')->get()
            ->map(fn ($x) => ['date' => substr((string) $x->d, 0, 10), 'count' => (int) $x->n, 'total' => round((float) $x->total, 2)])->all();
    }
}

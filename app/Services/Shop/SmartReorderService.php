<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Support\LocalDate;
use App\Support\LocalTime;
use App\Support\SalesSource;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Smarter reordering (SUPERMARKET_PLAN.md D5, the shop's `smart_reorder` feature), on top of
 * ProductStatsService's reorder list:
 *  - Demand: the last 8 whole weeks by weekday (a Saturday forecast from the last 8 Saturdays), net of
 *    returns and voids. Units sold with a promotion discount count at PROMO_WEIGHT, so a promotion spike
 *    does not become the next order. Seasons are not modelled (8 weeks follow the season as it moves).
 *  - Quantity: the demand until the next delivery (the supplier's lead time) plus the minimum as safety
 *    stock, less what is on hand; rounded up to whole packs (stock_items.purchase_unit_id → units.factor);
 *    a supplier's lines are topped up by whole packs to its minimum order value (suppliers.min_order_value).
 *  - Also lists products above their minimum that will run out before the next delivery.
 *  - Supplier scorecard: fill rate, days late against the lead time, and cost changes.
 */
class SmartReorderService
{
    public const WEEKS = 8;

    /** A unit sold with a promotion discount counts as this much of a normal sale. */
    public const PROMO_WEIGHT = 0.25;

    /** Topping up to a minimum order never goes beyond this many times the suggested quantity per line. */
    public const TOP_UP_LIMIT = 3;

    public static function on(int $companyId): bool
    {
        return StoreFeatures::enabled(Company::withoutGlobalScopes()->find($companyId), 'smart_reorder');
    }

    /**
     * Weekday demand per product over the last 8 whole weeks (to yesterday).
     *
     * @param  array<int, int>|null  $itemIds  null = every product that sold
     * @return array<int, array{weekday: array<int, float>, daily: float, promo_units: float, units: float}> stock item id => demand (per day, weekday 1 = Sunday … 7 = Saturday)
     */
    public function demand(int $companyId, ?array $itemIds = null): array
    {
        if ($itemIds === []) {
            return [];
        }
        LocalTime::prime($companyId);
        $today = LocalDate::today($companyId);
        $from = $today->copy()->subDays(self::WEEKS * 7)->toDateString();
        $to = $today->copy()->subDay()->toDateString();
        $coarse = Carbon::parse($from)->subDay()->toDateString();
        $saleDay = SalesSource::localDay('r.sale_date', 'r.created_at');
        $moveDay = SalesSource::localDay('m.date', 'm.created_at');
        $w = self::PROMO_WEIGHT;
        $ids = $itemIds !== null ? ' AND x.stock_item_id IN ('.implode(',', array_map('intval', $itemIds)).')' : '';
        $sql = "SELECT x.stock_item_id, DAYOFWEEK(x.day) AS wd, SUM(x.qty * x.weight) AS units, SUM(CASE WHEN x.weight < 1 THEN x.qty ELSE 0 END) AS promo, SUM(x.qty) AS raw_units
            FROM (
                SELECT i.stock_item_id, {$saleDay} AS day,
                    (i.quantity - COALESCE(i.returned_quantity, 0)) * COALESCE(NULLIF(i.unit_factor, 0), 1) AS qty,
                    CASE WHEN COALESCE(i.promo_discount, 0) > 0 THEN {$w} ELSE 1 END AS weight
                FROM sale_record_items i JOIN sale_records r ON r.id = i.sale_record_id
                WHERE r.company_id = ? AND r.voided_at IS NULL AND r.status <> 'Voided' AND r.sale_date >= ?
                UNION ALL
                SELECT m.stock_item_id, {$moveDay}, -m.quantity_delta, 1
                FROM stock_records m
                WHERE m.company_id = ? AND m.type = 'Sale' AND m.sale_record_id IS NULL AND m.is_reversal = 0 AND m.date >= ?
                  AND NOT EXISTS (SELECT 1 FROM stock_records rv WHERE rv.reverses_id = m.id)
            ) x
            WHERE x.day BETWEEN ? AND ?{$ids}
            GROUP BY x.stock_item_id, DAYOFWEEK(x.day)";
        $out = [];
        foreach (DB::select($sql, [$companyId, $coarse, $companyId, $coarse, $from, $to]) as $r) {
            $id = (int) $r->stock_item_id;
            $out[$id] ??= ['weekday' => array_fill(1, 7, 0.0), 'daily' => 0.0, 'promo_units' => 0.0, 'units' => 0.0];
            $out[$id]['weekday'][(int) $r->wd] = max(0.0, (float) $r->units / self::WEEKS);
            $out[$id]['promo_units'] += (float) $r->promo;
            $out[$id]['units'] += (float) $r->raw_units;
        }
        foreach ($out as $id => $d) {
            $out[$id]['daily'] = round(array_sum($d['weekday']) / 7, 3);
        }

        return $out;
    }

    /** Units expected to sell over the next $days days, from today, by weekday. */
    public function forecast(array $demand, int $days, Carbon $today): float
    {
        $sum = 0.0;
        for ($d = 0; $d < max(1, $days); $d++) {
            $sum += $demand['weekday'][(int) $today->copy()->addDays($d)->dayOfWeek + 1] ?? 0.0;
        }

        return round($sum, 3);
    }

    /**
     * ProductStatsService::suggestions, made smarter (called by it when the feature is on). Adds per row:
     * demand_daily, forecast_lead, promo_units, pack_size, pack_unit, packs, min_order_value, supplier_total,
     * below_min_order, topped_up.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function refine(Company $company, array $rows, float $defaultMin): array
    {
        $cid = (int) $company->id;
        $today = LocalDate::today($cid);
        $demand = $this->demand($cid);
        $rows = array_merge($rows, $this->runningOut($cid, $rows, $demand, $defaultMin, $today));
        if ($rows === []) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r['stock_item_id'], $rows);
        $packs = $this->packSizes($cid, $ids);
        $supplierIds = array_values(array_unique(array_filter(array_map(fn ($r) => (int) ($r['supplier_id'] ?? 0), $rows))));
        $suppliers = $supplierIds ? DB::table('suppliers')->where('company_id', $cid)->whereIn('id', $supplierIds)->get(['id', 'lead_time_days', 'min_order_value'])->keyBy('id') : collect();
        $pricesOn = SupplierPriceService::on($cid);
        $prices = [];
        if ($pricesOn) {
            foreach ($supplierIds as $sid) {
                $prices[$sid] = (new SupplierPriceService())->currentMany($cid, $sid, $ids);
            }
        }
        foreach ($rows as &$r) {
            $id = (int) $r['stock_item_id'];
            $d = $demand[$id] ?? null;
            $lead = (int) $r['lead_time_days'];
            $onHand = (float) $r['on_hand'];
            $min = (float) $r['min_stock'];
            $forecast = $d ? $this->forecast($d, $lead, $today) : 0.0;
            $need = $forecast + $min - $onHand;
            $why = $d && $forecast > 0
                ? 'Expect to sell about '.self::n($forecast)." in the {$lead} days until the next delivery (by weekday, last ".self::WEEKS.' weeks), plus '.self::n($min).' kept in reserve.'
                : null;
            if ($need <= 0 || $why === null) {
                $need = max($need, $min * 2 - $onHand);
                $why ??= 'Brings stock back to twice the minimum ('.self::n($min * 2).').';
            }
            $qty = max(1.0, ceil($need));
            $pack = $packs[$id] ?? null;
            if ($pack) {
                $qty = ceil($qty / $pack['size']) * $pack['size'];
                $why .= ' Rounded up to whole '.mb_strtolower($pack['name']).'s of '.self::n($pack['size']).'.';
            }
            if (! empty($r['running_out'])) {
                $why = 'Above its minimum, but will run out before the next delivery. '.$why;
            }
            if ($d && $d['promo_units'] > 0) {
                $why .= ' '.self::n($d['promo_units']).' sold on promotion counted at '.(int) (self::PROMO_WEIGHT * 100).'%.';
            }
            $sid = (int) ($r['supplier_id'] ?? 0);
            if ($sid && isset($prices[$sid][$id])) {
                $r['unit_cost'] = $prices[$sid][$id];
            }
            $r['avg_daily_sales'] = $d ? $d['daily'] : (float) $r['avg_daily_sales'];
            $r['demand_daily'] = $d ? $d['daily'] : 0.0;
            $r['forecast_lead'] = $forecast;
            $r['promo_units'] = $d ? round($d['promo_units'], 3) : 0.0;
            $r['pack_size'] = $pack['size'] ?? null;
            $r['pack_unit'] = $pack['name'] ?? null;
            $r['suggested_quantity'] = $qty;
            $r['packs'] = $pack ? (int) round($qty / $pack['size']) : null;
            $r['why'] = $why;
            $r['topped_up'] = 0.0;
            $r['min_order_value'] = $sid && isset($suppliers[$sid]) && $suppliers[$sid]->min_order_value !== null ? (float) $suppliers[$sid]->min_order_value : null;
        }
        unset($r);
        $rows = $this->topUp($rows);
        foreach ($rows as &$r) {
            $r['estimated_cost'] = round($r['suggested_quantity'] * (float) $r['unit_cost'], 2);
        }
        unset($r);

        return $rows;
    }

    /**
     * Products above their minimum whose expected sales until the next delivery are more than what is on hand.
     *
     * @return list<array<string, mixed>>
     */
    private function runningOut(int $cid, array $rows, array $demand, float $defaultMin, Carbon $today): array
    {
        $listed = array_flip(array_map(fn ($r) => (int) $r['stock_item_id'], $rows));
        $ids = array_values(array_filter(array_keys($demand), fn ($id) => ! isset($listed[$id]) && $demand[$id]['daily'] > 0));
        if ($ids === []) {
            return [];
        }
        $out = [];
        $stats = new ProductStatsService();
        foreach (array_chunk($ids, 1000) as $chunk) {
            $items = DB::table('stock_items as si')->where('si.company_id', $cid)->where('si.is_deleted', false)->where('si.track_stock', true)->whereIn('si.id', $chunk)
                ->leftJoin('product_stats as ps', 'ps.stock_item_id', '=', 'si.id')
                ->get(['si.id', 'si.name', 'si.current_quantity', 'si.min_stock', 'si.buying_price', 'ps.sold_30']);
            $suppliers = $stats->lastSuppliers($items->pluck('id')->map(fn ($id) => (int) $id)->all());
            foreach ($items as $i) {
                $supplier = $suppliers[(int) $i->id] ?? null;
                $lead = (int) ($supplier->lead_time_days ?? ProductStatsService::DEFAULT_LEAD_DAYS);
                $onHand = (float) $i->current_quantity;
                if ($this->forecast($demand[(int) $i->id], $lead, $today) < $onHand) {
                    continue;
                }
                $out[] = ['stock_item_id' => $i->id, 'name' => $i->name, 'on_hand' => $onHand, 'min_stock' => $i->min_stock === null ? $defaultMin : (float) $i->min_stock,
                    'avg_daily_sales' => $demand[(int) $i->id]['daily'], 'sold_30' => (float) ($i->sold_30 ?? 0), 'suggested_quantity' => 1, 'unit_cost' => (float) $i->buying_price,
                    'estimated_cost' => 0.0, 'supplier_id' => $supplier->id ?? null, 'supplier_name' => $supplier->name ?? null, 'lead_time_days' => $lead, 'why' => '',
                    'runs_out_in_days' => null, 'running_out' => true];
            }
        }

        return $out;
    }

    /**
     * A supplier's lines below its minimum order value get whole packs added, the line that runs out soonest
     * first, until the minimum is reached (never more than TOP_UP_LIMIT × a line's quantity). A supplier
     * still below its minimum is flagged below_min_order.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function topUp(array $rows): array
    {
        $groups = [];
        foreach ($rows as $k => $r) {
            $groups[(int) ($r['supplier_id'] ?? 0)][] = $k;
        }
        foreach ($groups as $sid => $keys) {
            $min = $sid ? ($rows[$keys[0]]['min_order_value'] ?? null) : null;
            $total = array_sum(array_map(fn ($k) => $rows[$k]['suggested_quantity'] * (float) $rows[$k]['unit_cost'], $keys));
            if ($min !== null && $min > 0 && $total < $min) {
                $cap = [];
                foreach ($keys as $k) {
                    $cap[$k] = $rows[$k]['suggested_quantity'] * self::TOP_UP_LIMIT;
                }
                for ($guard = 0; $total < $min && $guard < 1000; $guard++) {
                    $best = null;
                    $bestCover = INF;
                    foreach ($keys as $k) {
                        $step = (float) ($rows[$k]['pack_size'] ?? 1) ?: 1.0;
                        if ((float) $rows[$k]['unit_cost'] <= 0 || $rows[$k]['suggested_quantity'] + $step > $cap[$k]) {
                            continue;
                        }
                        $daily = max(0.001, (float) ($rows[$k]['demand_daily'] ?? 0));
                        $cover = ($rows[$k]['on_hand'] + $rows[$k]['suggested_quantity']) / $daily;
                        if ($cover < $bestCover) {
                            $bestCover = $cover;
                            $best = $k;
                        }
                    }
                    if ($best === null) {
                        break;
                    }
                    $step = (float) ($rows[$best]['pack_size'] ?? 1) ?: 1.0;
                    $rows[$best]['suggested_quantity'] += $step;
                    $rows[$best]['topped_up'] += $step;
                    $total += $step * (float) $rows[$best]['unit_cost'];
                }
                foreach ($keys as $k) {
                    if ($rows[$k]['topped_up'] > 0) {
                        $rows[$k]['why'] .= ' +'.self::n($rows[$k]['topped_up']).' to reach the supplier\'s minimum order.';
                        if ($rows[$k]['pack_size']) {
                            $rows[$k]['packs'] = (int) round($rows[$k]['suggested_quantity'] / $rows[$k]['pack_size']);
                        }
                    }
                }
            }
            foreach ($keys as $k) {
                $rows[$k]['supplier_total'] = round($total, 2);
                $rows[$k]['below_min_order'] = $min !== null && $min > 0 && $total < $min;
            }
        }

        return $rows;
    }

    /**
     * Pack size per product: the factor of its purchase unit (stock_items.purchase_unit_id), when above 1.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{size: float, name: string, unit_id: int}>
     */
    public function packSizes(int $companyId, array $ids): array
    {
        if ($ids === [] || ! Schema::hasColumn('stock_items', 'purchase_unit_id')) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = DB::table('stock_items as si')->join('units as u', 'u.id', '=', 'si.purchase_unit_id')
                ->where('si.company_id', $companyId)->where('u.company_id', $companyId)->whereIn('si.id', $chunk)->where('u.factor', '>', 1)
                ->get(['si.id', 'u.id as unit_id', 'u.name', 'u.factor']);
            foreach ($rows as $r) {
                $out[(int) $r->id] = ['size' => (float) $r->factor, 'name' => (string) $r->name, 'unit_id' => (int) $r->unit_id];
            }
        }

        return $out;
    }

    /** Round a quantity up to whole packs of the product's purchase unit (unchanged without one). */
    public function roundToPacks(int $companyId, int $stockItemId, float $qty): float
    {
        $pack = $this->packSizes($companyId, [$stockItemId])[$stockItemId] ?? null;

        return $pack ? ceil(round($qty / $pack['size'], 6)) * $pack['size'] : $qty;
    }

    /** The unit the product is bought in (a carton of 24…), or null for single units. */
    public function setPackUnit(StockItem $item, ?int $unitId): StockItem
    {
        if (! self::on((int) $item->company_id)) {
            throw BusinessRuleException::make('feature_off', 'Smarter reordering is off for this shop. Turn it on in Settings.');
        }
        if ($unitId !== null && ! DB::table('units')->where('company_id', $item->company_id)->where('id', $unitId)->where('is_deleted', 0)->exists()) {
            throw BusinessRuleException::make('unit_not_found', 'Unit not found.');
        }
        $item->forceFill(['purchase_unit_id' => $unitId])->save();

        return $item;
    }

    /** The smallest order the supplier accepts (in money); null = no minimum. */
    public function setMinOrderValue(Supplier $supplier, mixed $value): Supplier
    {
        if (! self::on((int) $supplier->company_id)) {
            throw BusinessRuleException::make('feature_off', 'Smarter reordering is off for this shop. Turn it on in Settings.');
        }
        $value = $value === null || $value === '' ? null : $value;
        if ($value !== null && (! is_numeric($value) || (float) $value < 0 || (float) $value > 100000000000)) {
            throw BusinessRuleException::make('invalid_amount', 'The minimum order must be a positive amount.');
        }
        $supplier->forceFill(['min_order_value' => $value === null || (float) $value == 0.0 ? null : round((float) $value, 2)])->save();

        return $supplier;
    }

    /**
     * How a supplier has done over the last $days days:
     *  - fill_rate: % of what was ordered that arrived (orders received, part received, or sent and overdue);
     *  - avg_days_late: average days between the promised day (expected date, else order date + lead time)
     *    and the first delivery (negative = early); on_time_pct: share delivered by the promised day;
     *  - price changes: products whose cost from this supplier changed, and the average change %.
     *
     * @return array{orders: int, fill_rate: ?float, deliveries_timed: int, avg_days_late: ?float, avg_lead_days: ?float, on_time_pct: ?float, lead_time_days: int, products_priced: int, price_changes: int, price_change_pct: ?float, changes: list<array{name: string, from: float, to: float, pct: float}>}
     */
    public function scorecard(Supplier $supplier, int $days = 90, ?string $from = null, ?string $to = null): array
    {
        $cid = (int) $supplier->company_id;
        $today = LocalDate::today($cid);
        $since = $from ?? $today->copy()->subDays($days)->toDateString();
        $until = $to ?? $today->toDateString();
        $lead = (int) ($supplier->lead_time_days ?? ProductStatsService::DEFAULT_LEAD_DAYS);
        $orders = DB::table('purchase_orders')->where('company_id', $cid)->where('supplier_id', $supplier->id)->whereNull('deleted_at')
            ->where('order_date', '>=', $since)->where('order_date', '<', Carbon::parse($until)->addDay()->toDateString())
            ->where(fn ($q) => $q->whereIn('status', ['received', 'partially_received'])
                ->orWhere(fn ($w) => $w->where('status', 'sent')->whereRaw('COALESCE(expected_date, DATE_ADD(DATE(order_date), INTERVAL ? DAY)) < ?', [$lead, $today->toDateString()])))
            ->get(['id', 'order_date', 'expected_date', 'sent_at']);
        $ids = $orders->pluck('id')->all();
        $fill = null;
        if ($ids) {
            $t = DB::table('purchase_order_items')->whereIn('purchase_order_id', $ids)
                ->selectRaw('COALESCE(SUM(quantity), 0) AS ordered, COALESCE(SUM(LEAST(received_quantity, quantity)), 0) AS received')->first();
            $fill = (float) $t->ordered > 0 ? round((float) $t->received / (float) $t->ordered * 100, 1) : null;
        }
        $first = $ids ? DB::table('goods_receipts')->whereIn('purchase_order_id', $ids)->where('is_deleted', 0)->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, MIN(received_on) AS first_on')->pluck('first_on', 'purchase_order_id') : collect();
        $late = [];
        $leads = [];
        foreach ($orders as $o) {
            if (! isset($first[$o->id])) {
                continue;
            }
            $ordered = Carbon::parse(substr((string) ($o->sent_at ?? $o->order_date), 0, 10));
            $promised = $o->expected_date ? Carbon::parse(substr((string) $o->expected_date, 0, 10)) : $ordered->copy()->addDays($lead);
            $got = Carbon::parse(substr((string) $first[$o->id], 0, 10));
            $late[] = (int) $promised->diffInDays($got, false);
            $leads[] = (int) $ordered->diffInDays($got, false);
        }
        $prices = $this->priceChanges($cid, (int) $supplier->id, $since, $until);

        return [
            'orders' => count($ids), 'fill_rate' => $fill, 'deliveries_timed' => count($late),
            'avg_days_late' => $late ? round(array_sum($late) / count($late), 1) : null,
            'avg_lead_days' => $leads ? round(array_sum($leads) / count($leads), 1) : null,
            'on_time_pct' => $late ? round(count(array_filter($late, fn ($d) => $d <= 0)) / count($late) * 100, 1) : null,
            'lead_time_days' => $lead,
        ] + $prices;
    }

    /**
     * Cost changes from this supplier since $since: each product's cost received before (or at the start of)
     * the window against the latest one, from deliveries (and the price list when there is one).
     *
     * @return array{products_priced: int, price_changes: int, price_change_pct: ?float, changes: list<array{name: string, from: float, to: float, pct: float}>}
     */
    private function priceChanges(int $cid, int $supplierId, string $since, string $until): array
    {
        $rows = DB::table('goods_receipt_items as gi')->join('goods_receipts as g', 'g.id', '=', 'gi.goods_receipt_id')
            ->join('stock_items as si', 'si.id', '=', 'gi.stock_item_id')
            ->where('g.company_id', $cid)->where('g.supplier_id', $supplierId)->where('g.is_deleted', 0)->where('gi.unit_cost', '>', 0)
            ->orderBy('g.received_on')->orderBy('gi.id')->get(['gi.stock_item_id', 'si.name', 'gi.unit_cost', 'g.received_on']);
        $series = [];
        foreach ($rows as $r) {
            $series[(int) $r->stock_item_id][] = ['on' => substr((string) $r->received_on, 0, 10), 'cost' => (float) $r->unit_cost, 'name' => (string) $r->name];
        }
        if (Schema::hasTable('supplier_prices')) {
            $manual = DB::table('supplier_prices as p')->join('stock_items as si', 'si.id', '=', 'p.stock_item_id')
                ->where('p.company_id', $cid)->where('p.supplier_id', $supplierId)->where('p.source', 'manual')->whereNull('p.unit_id')
                ->orderBy('p.valid_from')->get(['p.stock_item_id', 'si.name', 'p.cost', 'p.valid_from']);
            foreach ($manual as $m) {
                $series[(int) $m->stock_item_id][] = ['on' => (string) $m->valid_from, 'cost' => (float) $m->cost, 'name' => (string) $m->name];
            }
        }
        $changes = [];
        $priced = 0;
        foreach ($series as $points) {
            usort($points, fn ($a, $b) => strcmp($a['on'], $b['on']));
            $inWindow = array_values(array_filter($points, fn ($p) => $p['on'] >= $since && $p['on'] <= $until));
            if ($inWindow === []) {
                continue;
            }
            $priced++;
            $before = array_values(array_filter($points, fn ($p) => $p['on'] < $since));
            $start = $before ? end($before)['cost'] : $inWindow[0]['cost'];
            $end = end($inWindow)['cost'];
            if ($start > 0 && abs($end - $start) >= 0.005) {
                $changes[] = ['name' => $inWindow[0]['name'], 'from' => round($start, 2), 'to' => round($end, 2), 'pct' => round(($end - $start) / $start * 100, 1)];
            }
        }
        usort($changes, fn ($a, $b) => abs($b['pct']) <=> abs($a['pct']));

        return ['products_priced' => $priced, 'price_changes' => count($changes),
            'price_change_pct' => $changes ? round(array_sum(array_column($changes, 'pct')) / count($changes), 1) : null, 'changes' => array_slice($changes, 0, 10)];
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}

<?php

namespace App\Services\Shop;

use App\Models\Company;
use App\Services\Billing\Quotas;
use Illuminate\Support\Facades\DB;

/**
 * Sales velocity per product (plan A7, P4-3) and the reorder list built on it
 * (plan A5): products at or under their minimum, suggested quantity =
 * max(min × 2 − on hand, average daily sales × supplier lead time).
 */
class ProductStatsService
{
    public const DEFAULT_LEAD_DAYS = 7;

    /** Recompute product_stats for one company (or all). Net of voids/returns (reversals). */
    public function refresh(?int $companyId = null): int
    {
        $companies = $companyId ? [$companyId] : DB::table('companies')->pluck('id')->all();
        $n = 0;
        foreach ($companies as $cid) {
            $rows = DB::table('stock_items as si')->where('si.company_id', $cid)->where('si.is_deleted', false)
                ->leftJoin('stock_records as sr', function ($j) {
                    $j->on('sr.stock_item_id', '=', 'si.id')->where('sr.type', 'Sale')->where('sr.date', '>=', now()->subDays(90));
                })
                ->groupBy('si.id', 'si.current_quantity')
                ->select('si.id', 'si.current_quantity',
                    DB::raw('COALESCE(-SUM(CASE WHEN sr.date >= "'.now()->subDays(7)->toDateTimeString().'" THEN sr.quantity_delta END), 0) AS s7'),
                    DB::raw('COALESCE(-SUM(CASE WHEN sr.date >= "'.now()->subDays(30)->toDateTimeString().'" THEN sr.quantity_delta END), 0) AS s30'),
                    DB::raw('COALESCE(-SUM(sr.quantity_delta), 0) AS s90'),
                    DB::raw('COALESCE(SUM(CASE WHEN sr.date >= "'.now()->subDays(30)->toDateTimeString().'" THEN sr.total_sales END), 0) AS rev30'),
                    DB::raw('COALESCE(SUM(CASE WHEN sr.date >= "'.now()->subDays(30)->toDateTimeString().'" THEN sr.profit END), 0) AS prof30'),
                    DB::raw('MAX(CASE WHEN sr.is_reversal = 0 THEN sr.date END) AS last_sold'))
                ->get();
            foreach ($rows as $r) {
                $avg = round(max(0, (float) $r->s30) / 30, 3);
                DB::table('product_stats')->updateOrInsert(['stock_item_id' => $r->id], [
                    'company_id' => $cid, 'sold_7' => max(0, (float) $r->s7), 'sold_30' => max(0, (float) $r->s30), 'sold_90' => max(0, (float) $r->s90),
                    'revenue_30' => round((float) $r->rev30, 2), 'profit_30' => round((float) $r->prof30, 2), 'avg_daily_30' => $avg,
                    'last_sold_at' => $r->last_sold, 'days_of_cover' => $avg > 0 ? round(max(0, (float) $r->current_quantity) / $avg, 1) : null, 'computed_at' => now(),
                ]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestions(int $companyId): array
    {
        $company = Company::withoutGlobalScopes()->findOrFail($companyId);
        $default = (float) ($company->low_stock_default ?? config('saas.low_stock_threshold', 10));
        $forecasting = (new Quotas())->featureOn($company, 'forecasting');
        $rows = DB::table('stock_items as si')->where('si.company_id', $companyId)->where('si.is_deleted', false)->where('si.track_stock', true)
            ->whereRaw('si.current_quantity <= COALESCE(si.min_stock, ?)', [$default])
            ->leftJoin('product_stats as ps', 'ps.stock_item_id', '=', 'si.id')
            ->select('si.id', 'si.name', 'si.current_quantity', 'si.min_stock', 'si.buying_price', 'ps.avg_daily_30', 'ps.sold_30', 'ps.days_of_cover')
            ->orderBy('si.current_quantity')->get();
        $suppliers = $this->lastSuppliers($rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $out = [];
        foreach ($rows as $r) {
            $supplier = $suppliers[(int) $r->id] ?? null;
            $lead = (int) ($supplier->lead_time_days ?? self::DEFAULT_LEAD_DAYS);
            $min = $r->min_stock === null ? $default : (float) $r->min_stock;
            $onHand = (float) $r->current_quantity;
            $avg = (float) ($r->avg_daily_30 ?? 0);
            $byMin = $min * 2 - $onHand;
            $bySales = $avg * $lead;
            $qty = max(1, (int) ceil(max($byMin, $bySales)));
            $out[] = [
                'stock_item_id' => $r->id, 'name' => $r->name, 'on_hand' => $onHand, 'min_stock' => $min, 'avg_daily_sales' => $avg, 'sold_30' => (float) ($r->sold_30 ?? 0),
                'suggested_quantity' => $qty, 'unit_cost' => (float) $r->buying_price, 'estimated_cost' => round($qty * (float) $r->buying_price, 2),
                'supplier_id' => $supplier->id ?? null, 'supplier_name' => $supplier->name ?? null, 'lead_time_days' => $lead,
                'why' => $bySales > $byMin
                    ? 'Sells about '.rtrim(rtrim(number_format($avg, 1), '0'), '.')." a day; {$lead} days of stock until the next delivery."
                    : 'Brings stock back to twice the minimum ('.rtrim(rtrim(number_format($min * 2, 3, '.', ''), '0'), '.').').',
                'runs_out_in_days' => $forecasting && $avg > 0 ? (int) floor(max(0, $onHand) / $avg) : null,
            ];
        }

        return $out;
    }

    /**
     * Each product's supplier on its latest goods receipt (plan A5: one grouped query instead of one per product).
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, object{id: int, name: string, lead_time_days: int|null}> stock item id => supplier
     */
    private function lastSuppliers(array $itemIds): array
    {
        $out = [];
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $latest = DB::table('goods_receipt_items as gi')->join('goods_receipts as g', 'g.id', '=', 'gi.goods_receipt_id')
                ->join('suppliers as s', 's.id', '=', 'g.supplier_id')->whereIn('gi.stock_item_id', $chunk)
                ->groupBy('gi.stock_item_id')->select('gi.stock_item_id', DB::raw('MAX(g.id) AS receipt_id'));
            $rows = DB::query()->fromSub($latest, 'x')->join('goods_receipts as g', 'g.id', '=', 'x.receipt_id')->join('suppliers as s', 's.id', '=', 'g.supplier_id')
                ->get(['x.stock_item_id', 's.id', 's.name', 's.lead_time_days']);
            foreach ($rows as $r) {
                $out[(int) $r->stock_item_id] = $r;
            }
        }

        return $out;
    }

    /**
     * Turn picked suggestions into draft purchase orders, one per supplier.
     *
     * @param  array<int, array{stock_item_id: int, quantity: float|string, supplier_id?: int|null, unit_cost?: float|string|null}>  $picks
     * @return array<int, \App\Models\PurchaseOrder>
     */
    public function createOrders(int $companyId, int $userId, array $picks): array
    {
        $groups = [];
        foreach ($picks as $p) {
            $groups[(int) ($p['supplier_id'] ?? 0)][] = ['stock_item_id' => (int) $p['stock_item_id'], 'quantity' => $p['quantity'], 'unit_cost' => $p['unit_cost'] ?? null];
        }
        $orders = [];
        foreach ($groups as $supplierId => $lines) {
            $orders[] = (new PurchaseOrderService())->create($companyId, $userId, $lines, $supplierId ?: null, null, 'From the reorder list');
        }

        return $orders;
    }
}

<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consignment, sale-or-return (SUPERMARKET_PLAN.md D8, the shop's `consignment` feature): a product marked
 * consigned from a supplier (stock_items.consignment_supplier_id) stays the supplier's until it is sold.
 *  - Receiving it from that supplier owes nothing (GoodsReceiptService: goods_receipts.consignment_value).
 *  - Settling bills what was sold since the last settlement at the agreed cost: a consignment_settlements
 *    row that SupplierService::balance() counts as owed, paid like any other supplier balance.
 *  - Unsold stock goes back through supplier returns, which then take nothing off what is owed.
 * "Sold" is the net of Sale and Return movements after a watermark (the last settled stock_records.id; the
 * first consigned delivery's movement before any settlement), so voids and refunds settle correctly.
 */
class ConsignmentService
{
    public static function on(int $companyId): bool
    {
        return Schema::hasColumn('stock_items', 'consignment_supplier_id')
            && StoreFeatures::enabled(Company::withoutGlobalScopes()->find($companyId), 'consignment');
    }

    /** Mark a product consigned from a supplier, or (null) owned by the shop again. */
    public function setSupplier(StockItem $item, ?int $supplierId): StockItem
    {
        if (! self::on((int) $item->company_id)) {
            throw BusinessRuleException::make('feature_off', 'Consignment is off for this shop. Turn it on in Settings.');
        }
        if ($supplierId !== null && ! Supplier::withoutGlobalScopes()->where('company_id', $item->company_id)->where('is_deleted', 0)->whereKey($supplierId)->exists()) {
            throw BusinessRuleException::make('supplier_not_found', 'Supplier not found.');
        }
        $item->forceFill(['consignment_supplier_id' => $supplierId])->save();

        return $item;
    }

    /**
     * What was sold of this supplier's consigned products since the last settlement, and what it comes to.
     *
     * @return array{lines: list<array{stock_item_id: int, name: string, quantity: float, unit_cost: float, amount: float}>, quantity: float, amount: float, since: ?string, through_record_id: int, on_hand: float, on_hand_value: float, last_settled_on: ?string}
     */
    public function pending(Supplier $supplier): array
    {
        $cid = (int) $supplier->company_id;
        $empty = ['lines' => [], 'quantity' => 0.0, 'amount' => 0.0, 'since' => null, 'through_record_id' => 0, 'on_hand' => 0.0, 'on_hand_value' => 0.0, 'last_settled_on' => null];
        if (! Schema::hasTable('consignment_settlements')) {
            return $empty;
        }
        $items = DB::table('stock_items')->where('company_id', $cid)->where('consignment_supplier_id', $supplier->id)->where('is_deleted', 0)
            ->get(['id', 'name', 'current_quantity', 'buying_price'])->keyBy('id');
        if ($items->isEmpty()) {
            return $empty;
        }
        $ids = $items->keys()->map(fn ($id) => (int) $id)->all();
        $last = DB::table('consignment_settlements')->where('company_id', $cid)->where('supplier_id', $supplier->id)->orderByDesc('id')->first(['through_record_id', 'settled_on']);
        $first = DB::table('goods_receipt_items as gi')->join('goods_receipts as g', 'g.id', '=', 'gi.goods_receipt_id')
            ->where('g.company_id', $cid)->where('g.supplier_id', $supplier->id)->where('gi.is_consignment', 1)->min('gi.stock_record_id');
        $through = (int) DB::table('stock_records')->where('company_id', $cid)->max('id');
        $costs = $this->agreedCosts($cid, (int) $supplier->id, $ids);
        $onHand = 0.0;
        $onHandValue = 0.0;
        foreach ($items as $id => $i) {
            $q = max(0.0, (float) $i->current_quantity);
            $onHand += $q;
            $onHandValue += $q * ($costs[(int) $id] ?? (float) $i->buying_price);
        }
        $base = ['on_hand' => round($onHand, 3), 'on_hand_value' => round($onHandValue, 2), 'last_settled_on' => $last?->settled_on, 'through_record_id' => $through];
        $watermark = $last ? (int) $last->through_record_id : ($first ? (int) $first - 1 : null);
        if ($watermark === null) {
            return $base + $empty; // nothing received on consignment yet
        }
        $sold = DB::table('stock_records')->where('company_id', $cid)->whereIn('stock_item_id', $ids)->whereIn('type', ['Sale', 'Return'])
            ->where('id', '>', $watermark)->where('id', '<=', $through)
            ->groupBy('stock_item_id')->selectRaw('stock_item_id, -SUM(quantity_delta) AS qty, MIN(date) AS since')->get();
        $lines = [];
        $since = null;
        foreach ($sold as $r) {
            $qty = round((float) $r->qty, 3);
            if (abs($qty) < 0.0005) {
                continue;
            }
            $cost = $costs[(int) $r->stock_item_id] ?? (float) ($items[$r->stock_item_id]->buying_price ?? 0);
            $lines[] = ['stock_item_id' => (int) $r->stock_item_id, 'name' => (string) ($items[$r->stock_item_id]->name ?? ''), 'quantity' => $qty,
                'unit_cost' => round($cost, 2), 'amount' => round($qty * $cost, 2)];
            $since = $since === null || (string) $r->since < $since ? (string) $r->since : $since;
        }
        usort($lines, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return ['lines' => $lines, 'quantity' => round(array_sum(array_column($lines, 'quantity')), 3), 'amount' => max(0.0, round(array_sum(array_column($lines, 'amount')), 2)),
            'since' => $since ? substr($since, 0, 10) : null] + $base;
    }

    /**
     * The agreed cost per product: the cost on the latest consigned delivery from this supplier, else the
     * supplier's price list, else the product's cost.
     *
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function agreedCosts(int $companyId, int $supplierId, array $ids): array
    {
        $out = (new SupplierPriceService())->currentMany($companyId, $supplierId, $ids);
        $rows = DB::table('goods_receipt_items as gi')->join('goods_receipts as g', 'g.id', '=', 'gi.goods_receipt_id')
            ->where('g.company_id', $companyId)->where('g.supplier_id', $supplierId)->where('gi.is_consignment', 1)->whereIn('gi.stock_item_id', $ids)
            ->orderBy('gi.id')->get(['gi.stock_item_id', 'gi.unit_cost']);
        foreach ($rows as $r) {
            $out[(int) $r->stock_item_id] = (float) $r->unit_cost; // the latest delivery wins
        }

        return $out;
    }

    /**
     * Bill the supplier's consigned sales since the last settlement: what the shop now owes them.
     *
     * @return object the consignment_settlements row
     */
    public function settle(Supplier $supplier, int $userId): object
    {
        $cid = (int) $supplier->company_id;
        if (! self::on($cid)) {
            throw BusinessRuleException::make('feature_off', 'Consignment is off for this shop. Turn it on in Settings.');
        }

        return DB::transaction(function () use ($supplier, $userId, $cid) {
            Supplier::withoutGlobalScopes()->lockForUpdate()->find($supplier->id); // one settlement at a time
            $p = $this->pending($supplier);
            if ($p['lines'] === [] || $p['amount'] <= 0) {
                throw BusinessRuleException::make('nothing_to_settle', "Nothing of {$supplier->name}'s consigned stock was sold since the last settlement.");
            }
            $id = DB::table('consignment_settlements')->insertGetId([
                'company_id' => $cid, 'supplier_id' => $supplier->id, 'number' => NumberSequencer::next($cid, 'CNS'),
                'settled_on' => LocalDate::today($cid)->toDateString(), 'through_record_id' => $p['through_record_id'], 'quantity' => $p['quantity'],
                'amount' => $p['amount'], 'lines' => json_encode($p['lines']), 'created_by_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            (new SupplierService())->recalc((int) $supplier->id);

            return DB::table('consignment_settlements')->find($id);
        });
    }

    /** @return list<object> the supplier's settlements, newest first */
    public function settlements(Supplier $supplier, int $limit = 10): array
    {
        if (! Schema::hasTable('consignment_settlements')) {
            return [];
        }

        return DB::table('consignment_settlements')->where('company_id', $supplier->company_id)->where('supplier_id', $supplier->id)
            ->orderByDesc('id')->limit($limit)->get(['id', 'number', 'settled_on', 'quantity', 'amount'])->all();
    }
}

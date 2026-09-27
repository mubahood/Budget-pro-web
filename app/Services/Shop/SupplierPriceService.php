<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier price lists (SUPERMARKET_PLAN.md D7, the shop's `supplier_prices` feature): what each supplier
 * charges for each product, with history. Every change is a new row (valid_from); the price today is the
 * latest row that has started. Receiving writes the delivery's cost here; a purchase order line with no
 * cost starts at the supplier's latest price.
 */
class SupplierPriceService
{
    public static function on(int $companyId): bool
    {
        static $has = null;
        $has ??= Schema::hasTable('supplier_prices');

        return $has && StoreFeatures::enabled(Company::withoutGlobalScopes()->find($companyId), 'supplier_prices');
    }

    /**
     * Record a supplier's cost for a product. Nothing is written when it is the same as the price already
     * in force from that day (so receiving the same cost again adds no history).
     */
    public function record(int $companyId, int $supplierId, int $stockItemId, float $cost, ?string $validFrom = null, ?int $unitId = null,
        string $source = 'manual', ?int $sourceId = null, ?int $userId = null): ?int
    {
        $cost = round($cost, 2);
        if ($cost < 0) {
            throw BusinessRuleException::make('invalid_cost', 'A cost cannot be negative.');
        }
        if (! DB::table('suppliers')->where('company_id', $companyId)->where('id', $supplierId)->exists()) {
            throw BusinessRuleException::make('supplier_not_found', 'Supplier not found.');
        }
        if (! DB::table('stock_items')->where('company_id', $companyId)->where('id', $stockItemId)->exists()) {
            throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
        }
        $from = $validFrom ? Carbon::parse($validFrom)->toDateString() : LocalDate::today($companyId)->toDateString();
        $current = $this->currentRow($companyId, $supplierId, $stockItemId, $from, $unitId);
        if ($current !== null && round((float) $current->cost, 2) === $cost) {
            return null;
        }

        return (int) DB::table('supplier_prices')->insertGetId([
            'company_id' => $companyId, 'supplier_id' => $supplierId, 'stock_item_id' => $stockItemId, 'unit_id' => $unitId, 'cost' => $cost,
            'valid_from' => $from, 'source' => $source, 'source_id' => $sourceId, 'created_by_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function currentRow(int $companyId, int $supplierId, int $stockItemId, ?string $on = null, ?int $unitId = null): ?object
    {
        $on ??= LocalDate::today($companyId)->toDateString();

        return DB::table('supplier_prices')->where('company_id', $companyId)->where('supplier_id', $supplierId)->where('stock_item_id', $stockItemId)
            ->where(fn ($q) => $unitId ? $q->where('unit_id', $unitId) : $q->whereNull('unit_id'))
            ->where('valid_from', '<=', $on)->orderByDesc('valid_from')->orderByDesc('id')->first();
    }

    /** The supplier's price for the product today (per base unit), or null when there is none. */
    public function current(int $companyId, int $supplierId, int $stockItemId): ?float
    {
        if (! Schema::hasTable('supplier_prices')) {
            return null;
        }
        $row = $this->currentRow($companyId, $supplierId, $stockItemId);

        return $row ? (float) $row->cost : null;
    }

    /**
     * Today's prices of one supplier for many products.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, float> stock item id => cost
     */
    public function currentMany(int $companyId, int $supplierId, array $itemIds): array
    {
        if ($itemIds === [] || ! Schema::hasTable('supplier_prices')) {
            return [];
        }
        $today = LocalDate::today($companyId)->toDateString();
        $out = [];
        $rows = DB::table('supplier_prices')->where('company_id', $companyId)->where('supplier_id', $supplierId)->whereNull('unit_id')
            ->whereIn('stock_item_id', $itemIds)->where('valid_from', '<=', $today)->orderBy('valid_from')->orderBy('id')->get(['stock_item_id', 'cost']);
        foreach ($rows as $r) {
            $out[(int) $r->stock_item_id] = (float) $r->cost; // the latest wins
        }

        return $out;
    }

    /**
     * What each supplier charges for a product, cheapest first: today's cost, since when, and the one before.
     *
     * @return list<array{supplier_id: int, supplier: string, cost: float, since: string, previous: ?float, change_pct: ?float, source: string}>
     */
    public function forProduct(int $companyId, int $stockItemId): array
    {
        if (! Schema::hasTable('supplier_prices')) {
            return [];
        }
        $today = LocalDate::today($companyId)->toDateString();
        $rows = DB::table('supplier_prices as p')->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.company_id', $companyId)->where('p.stock_item_id', $stockItemId)->whereNull('p.unit_id')->where('p.valid_from', '<=', $today)
            ->where('s.is_deleted', 0)->orderBy('p.valid_from')->orderBy('p.id')
            ->get(['p.supplier_id', 's.name', 'p.cost', 'p.valid_from', 'p.source']);
        $by = [];
        foreach ($rows as $r) {
            $id = (int) $r->supplier_id;
            $prev = $by[$id]['cost'] ?? null;
            $by[$id] = ['supplier_id' => $id, 'supplier' => (string) $r->name, 'cost' => (float) $r->cost, 'since' => (string) $r->valid_from,
                'previous' => $prev, 'change_pct' => $prev ? round(((float) $r->cost - $prev) / $prev * 100, 1) : null, 'source' => (string) $r->source];
        }
        $out = array_values($by);
        usort($out, fn ($a, $b) => $a['cost'] <=> $b['cost']);

        return $out;
    }

    /** @return list<object{cost: float, valid_from: string, source: string}> newest first */
    public function history(int $companyId, int $supplierId, int $stockItemId, int $limit = 20): array
    {
        if (! Schema::hasTable('supplier_prices')) {
            return [];
        }

        return DB::table('supplier_prices')->where('company_id', $companyId)->where('supplier_id', $supplierId)->where('stock_item_id', $stockItemId)
            ->orderByDesc('valid_from')->orderByDesc('id')->limit($limit)->get(['cost', 'valid_from', 'source'])->all();
    }
}

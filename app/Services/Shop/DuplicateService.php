<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate master data (plan Appendix E "Duplicate master"): the same customer,
 * supplier, category, unit or product created on two phones is kept twice and
 * offered as a merge. Merging points every document at the kept row, adds up
 * stock and tombstones the other so every phone drops it on the next pull.
 */
class DuplicateService
{
    /** table => [label, key SQL, display column] */
    public const KINDS = [
        'customers' => ['Customers with the same phone', "REPLACE(REPLACE(phone, ' ', ''), '-', '')", 'name'],
        'suppliers' => ['Suppliers with the same name', 'LOWER(TRIM(name))', 'name'],
        'stock_categories' => ['Categories with the same name', 'LOWER(TRIM(name))', 'name'],
        'units' => ['Units with the same name', 'LOWER(TRIM(name))', 'name'],
        'stock_items' => ['Products with the same barcode', 'TRIM(barcode)', 'name'],
    ];

    /** Every column that points at a row of that table (table => [[child table, column], …]). */
    private const REFERENCES = [
        'customers' => [['sale_records', 'customer_id'], ['payments', 'customer_id']],
        'suppliers' => [['goods_receipts', 'supplier_id'], ['purchase_orders', 'supplier_id'], ['purchase_returns', 'supplier_id']],
        'stock_categories' => [['stock_sub_categories', 'stock_category_id'], ['stock_items', 'stock_category_id'], ['stock_records', 'stock_category_id'], ['stock_takes', 'stock_category_id']],
        'units' => [['stock_items', 'unit_id'], ['product_barcodes', 'unit_id'], ['sale_record_items', 'unit_id']],
        'stock_items' => [['stock_records', 'stock_item_id'], ['sale_record_items', 'stock_item_id'], ['sale_return_items', 'stock_item_id'], ['goods_receipt_items', 'stock_item_id'],
            ['purchase_order_items', 'stock_item_id'], ['purchase_return_items', 'stock_item_id'], ['stock_take_items', 'stock_item_id'], ['stock_transfer_items', 'stock_item_id'],
            ['product_barcodes', 'stock_item_id']],
    ];

    /** @return array<int, array{kind: string, label: string, key: string, rows: array}> */
    public function find(int $companyId): array
    {
        $out = [];
        foreach (self::KINDS as $table => [$label, $key, $display]) {
            $groups = DB::table($table)->where('company_id', $companyId)->where('is_deleted', false)->whereRaw("{$key} IS NOT NULL AND {$key} <> ''")
                ->groupByRaw($key)->havingRaw('COUNT(*) > 1')->selectRaw("{$key} AS k")->pluck('k');
            foreach ($groups as $k) {
                $rows = DB::table($table)->where('company_id', $companyId)->where('is_deleted', false)->whereRaw("{$key} = ?", [$k])->orderBy('id')
                    ->get(array_values(array_unique(['id', $display, 'created_at'])))->map(fn ($r) => (array) $r)->all();
                $out[] = ['kind' => $table, 'label' => $label, 'key' => (string) $k, 'rows' => $rows];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $mergeIds
     */
    public function merge(int $companyId, string $table, int $keepId, array $mergeIds): int
    {
        if (! isset(self::KINDS[$table])) {
            throw BusinessRuleException::make('invalid_kind', 'That kind of record cannot be merged.');
        }
        $mergeIds = array_values(array_diff(array_map('intval', $mergeIds), [$keepId]));
        $ids = DB::table($table)->where('company_id', $companyId)->where('is_deleted', false)->whereIn('id', [$keepId, ...$mergeIds])->pluck('id')->all();
        if (! in_array($keepId, $ids, true) || count($ids) !== count($mergeIds) + 1 || $mergeIds === []) {
            throw BusinessRuleException::make('not_found', 'Choose records of this shop to merge.');
        }

        return DB::transaction(function () use ($companyId, $table, $keepId, $mergeIds) {
            if ($table === 'stock_items') {
                $this->mergeStock($companyId, $keepId, $mergeIds);
            }
            foreach (self::REFERENCES[$table] as [$child, $column]) {
                if (\Illuminate\Support\Facades\Schema::hasTable($child)) {
                    DB::table($child)->whereIn($column, $mergeIds)->update([$column => $keepId]);
                }
            }
            foreach ($mergeIds as $id) {
                DB::table($table)->where('id', $id)->update(['is_deleted' => true, 'server_seq' => SyncSequence::next(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }
            DB::table($table)->where('id', $keepId)->update(['server_seq' => SyncSequence::next(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            match ($table) {
                'customers' => (new CustomerService())->recalc($keepId),
                'suppliers' => (new SupplierService())->recalc($keepId),
                default => null,
            };

            return count($mergeIds);
        });
    }

    /** Quantities add up; per-location levels move to the kept product; its stats are rebuilt next night. */
    private function mergeStock(int $companyId, int $keepId, array $mergeIds): void
    {
        $extra = (float) DB::table('stock_items')->whereIn('id', $mergeIds)->sum('current_quantity');
        $original = (float) DB::table('stock_items')->whereIn('id', $mergeIds)->sum('original_quantity');
        DB::table('stock_items')->where('id', $keepId)->update(['current_quantity' => DB::raw('current_quantity + '.$extra), 'original_quantity' => DB::raw('original_quantity + '.$original)]);
        DB::table('stock_items')->whereIn('id', $mergeIds)->update(['current_quantity' => 0]);
        foreach (DB::table('stock_levels')->whereIn('stock_item_id', $mergeIds)->get() as $l) {
            LocationStock::adjust($companyId, (int) $l->location_id, $keepId, (float) $l->quantity);
        }
        DB::table('stock_levels')->whereIn('stock_item_id', $mergeIds)->delete();
        // Batches: the same batch at the same location becomes one; others move over.
        foreach (DB::table('stock_batches')->whereIn('stock_item_id', $mergeIds)->get() as $b) {
            $same = DB::table('stock_batches')->where('stock_item_id', $keepId)->where('location_id', $b->location_id)->where('batch_number', $b->batch_number)->value('id');
            if ($same) {
                DB::table('stock_batches')->where('id', $same)->update(['quantity' => DB::raw('quantity + '.(float) $b->quantity)]);
                DB::table('stock_record_batches')->where('stock_batch_id', $b->id)->update(['stock_batch_id' => $same]);
                DB::table('stock_batches')->where('id', $b->id)->delete();
            } else {
                DB::table('stock_batches')->where('id', $b->id)->update(['stock_item_id' => $keepId]);
            }
        }
        DB::table('product_stats')->whereIn('stock_item_id', $mergeIds)->delete();
    }
}

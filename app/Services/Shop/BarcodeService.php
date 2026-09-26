<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\ProductBarcode;
use App\Models\StockItem;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barcodes per product and per pack (SUPERMARKET_PLAN.md A1). A barcode is unique per shop: a can,
 * its 6-pack and its carton each have their own row in product_barcodes, and the product's own
 * `stock_items.barcode` is mirrored there as the primary row (base unit), so one indexed lookup
 * finds any of them (BarcodeResolver). `stock_items.barcode` keeps working exactly as before.
 *
 * Primary rows are only kept for shops with `pack_barcodes` on: a shop without the feature sees
 * (and syncs) exactly the rows it had.
 */
class BarcodeService
{
    private static ?bool $hasPrimary = null;

    public static function hasPrimaryColumn(): bool
    {
        return self::$hasPrimary ??= Schema::hasColumn('product_barcodes', 'is_primary');
    }

    /**
     * Copy every product's own barcode into product_barcodes as its primary row. Safe to run again:
     * products that already have their primary row, and barcodes another product already owns, are
     * left alone. Returns how many rows were written.
     */
    public static function backfillPrimary(int $companyId): int
    {
        if (! self::hasPrimaryColumn()) {
            return 0;
        }
        $done = 0;
        StockItem::withoutGlobalScopes()->without(['stockSubCategory', 'stockCategory'])
            ->where('company_id', $companyId)->where('is_deleted', 0)->whereNotNull('barcode')->where('barcode', '<>', '')
            ->orderBy('id')->select(['id', 'company_id', 'barcode', 'created_by_id'])
            ->chunkById(500, function ($items) use (&$done) {
                foreach ($items as $item) {
                    $done += self::writePrimary($item) ? 1 : 0;
                }
            });

        return $done;
    }

    /** After a product's barcode changes: keep its primary row in step (only for shops using pack barcodes). */
    public static function syncPrimary(StockItem $item): void
    {
        if (! self::hasPrimaryColumn()) {
            return;
        }
        $company = Company::withoutGlobalScopes()->find($item->company_id);
        if (! StoreFeatures::enabled($company, 'pack_barcodes')) {
            return;
        }
        self::writePrimary($item);
    }

    /** @return bool whether a row was written */
    private static function writePrimary(StockItem $item): bool
    {
        $code = trim((string) $item->barcode);
        $cid = (int) $item->company_id;
        $current = ProductBarcode::withoutGlobalScopes()->where('company_id', $cid)->where('stock_item_id', $item->id)
            ->where('is_primary', 1)->where('is_deleted', 0)->first();
        if ($current !== null && $current->barcode === $code) {
            return false;
        }
        if ($current !== null) {
            $current->delete(); // tombstone: phones drop it on their next sync
        }
        if ($code === '' || mb_strlen($code) > 64) {
            return $current !== null;
        }
        $row = ProductBarcode::withoutGlobalScopes()->where('company_id', $cid)->where('barcode', $code)->first();
        if ($row !== null && ! $row->is_deleted) {
            return $current !== null; // another row (a pack of this product, or another product) already owns it
        }
        if ($row !== null) {
            // A removed barcode comes back: the (company, barcode) index counts tombstones too.
            $row->forceFill(['stock_item_id' => $item->id, 'unit_id' => null, 'is_primary' => true, 'is_deleted' => 0])->save();

            return true;
        }
        $pb = new ProductBarcode();
        $pb->forceFill(['company_id' => $cid, 'stock_item_id' => $item->id, 'barcode' => $code, 'unit_id' => null, 'is_primary' => true, 'created_by_id' => $item->created_by_id]);
        $pb->save();

        return true;
    }

    /**
     * The name of the product that already uses this barcode (its own barcode or a pack barcode),
     * other than $exceptItemId; null when it is free.
     */
    public static function owner(int $companyId, string $barcode, ?int $exceptItemId = null): ?string
    {
        $code = trim($barcode);
        if ($code === '') {
            return null;
        }
        $viaRow = DB::table('product_barcodes as b')->join('stock_items as p', 'p.id', '=', 'b.stock_item_id')
            ->where('b.company_id', $companyId)->where('b.barcode', $code)->where('b.is_deleted', 0)->where('p.is_deleted', 0)
            ->when($exceptItemId, fn ($q) => $q->where('b.stock_item_id', '<>', $exceptItemId))
            ->value('p.name');
        if ($viaRow !== null) {
            return (string) $viaRow;
        }
        $own = DB::table('stock_items')->where('company_id', $companyId)->where('is_deleted', 0)->where('barcode', $code)
            ->when($exceptItemId, fn ($q) => $q->where('id', '<>', $exceptItemId))
            ->value('name');

        return $own !== null ? (string) $own : null;
    }

    /** The refusal for a barcode another product already has. */
    public static function refusal(string $barcode, string $productName): string
    {
        return "Barcode {$barcode} is already on {$productName}. A barcode can belong to one product only.";
    }

    /**
     * Add a pack (or extra) barcode to a product, refusing one another product has. A barcode that
     * was removed earlier is brought back rather than refused by the (company, barcode) index.
     */
    public static function add(int $companyId, int $stockItemId, string $barcode, ?int $unitId, ?int $userId = null): ProductBarcode
    {
        $code = trim($barcode);
        if ($code === '' || mb_strlen($code) > 64) {
            throw BusinessRuleException::make('invalid_barcode', 'A barcode needs 1 to 64 characters.');
        }
        if (($other = self::owner($companyId, $code, $stockItemId)) !== null) {
            throw BusinessRuleException::make('duplicate_barcode', self::refusal($code, $other), ['product' => $other]);
        }
        $row = ProductBarcode::withoutGlobalScopes()->where('company_id', $companyId)->where('barcode', $code)->first();
        if ($row !== null && ! $row->is_deleted) {
            throw BusinessRuleException::make('duplicate_barcode', 'This product already has barcode '.$code.'.');
        }
        $attrs = ['stock_item_id' => $stockItemId, 'unit_id' => $unitId ?: null, 'is_deleted' => 0];
        if (self::hasPrimaryColumn()) {
            $attrs['is_primary'] = false;
        }
        if ($row !== null) {
            $row->forceFill($attrs)->save();

            return $row;
        }
        $pb = new ProductBarcode();
        $pb->forceFill($attrs + ['company_id' => $companyId, 'barcode' => $code, 'created_by_id' => $userId]);
        $pb->save();

        return $pb;
    }
}

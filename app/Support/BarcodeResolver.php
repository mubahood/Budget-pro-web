<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * What a scan (or a typed code) at the till means (SUPERMARKET_PLAN.md A1, A2), in one indexed lookup:
 *
 *  - a GS1 in-store scale label (EAN-13 with a scale prefix, `weighed_items` on): the item code is the
 *    product's `plu_code`, and the label carries the weight (kg) or the price;
 *  - a PLU typed at the till (e.g. "4011", `weighed_items` on): the product, whose weight is asked for;
 *  - a barcode in product_barcodes (a pack barcode sells that pack unit; the primary row the base unit);
 *  - the product's own barcode or SKU (stock_items), exactly as before;
 *  - a markdown label ("MD…", `markdowns` on, B4): the product at the reduced `price`, with the
 *    markdown and its batch (`markdown_id`, `batch_id`), while that batch still has stock.
 *
 * Only active products of the shop are found.
 */
class BarcodeResolver
{
    public const SOLD_BY = ['unit', 'weight', 'length', 'volume'];

    /**
     * @return array{stock_item_id: int, unit_id: ?int, quantity: ?float, price: ?float, sold_by: string, source: string, needs_quantity: bool, markdown_id?: int, batch_id?: int}|null
     */
    public static function resolve(Company|int $company, string $code): ?array
    {
        $company = $company instanceof Company ? $company : Company::withoutGlobalScopes()->find($company);
        if ($company === null) {
            return null;
        }
        $cid = (int) $company->id;
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 100) {
            return null;
        }
        $weighed = StoreFeatures::enabled($company, 'weighed_items');

        if ($weighed) {
            $scale = self::decodeScale($code, self::scaleSettings($company));
            if ($scale !== null) {
                $p = self::byPlu($cid, $scale['item']);
                if ($p !== null) {
                    $price = (float) $p->selling_price;
                    $qty = $scale['kind'] === 'weight' ? $scale['value'] : ($price > 0 ? round($scale['value'] / $price, 3) : null);

                    return self::hit($p, null, $qty !== null && $qty > 0 ? $qty : null, $scale['kind'] === 'price' ? $scale['value'] : null, 'scale');
                }
            }
            if (preg_match('/^\d{1,6}$/', $code)) {
                $p = self::byPlu($cid, $code);
                if ($p !== null) {
                    return self::hit($p, null, null, null, 'plu');
                }
            }
        }

        if (StoreFeatures::enabled($company, 'markdowns')) {
            $m = \App\Services\Shop\MarkdownService::forBarcode($cid, $code);
            if ($m !== null) {
                // The reduced price is the price of one base unit of this product.
                return self::hit((object) ['id' => (int) $m->stock_item_id, 'sold_by' => $m->sold_by], null, null, (float) $m->price, 'markdown') + ['markdown_id' => (int) $m->id, 'batch_id' => (int) $m->stock_batch_id];
            }
        }

        $row = DB::table('product_barcodes as b')->join('stock_items as p', 'p.id', '=', 'b.stock_item_id')
            ->where('b.company_id', $cid)->where('b.barcode', $code)->where('b.is_deleted', 0)
            ->where('p.company_id', $cid)->where('p.is_deleted', 0)->where('p.is_active', 1)
            ->first(['p.id', 'p.sold_by', 'p.selling_price', 'b.unit_id']);
        if ($row !== null) {
            return self::hit($row, $row->unit_id ? (int) $row->unit_id : null, null, null, 'barcode');
        }
        $p = DB::table('stock_items')->where('company_id', $cid)->where('is_deleted', 0)->where('is_active', 1)
            ->where(fn ($w) => $w->where('barcode', $code)->orWhere('sku', $code))->orderBy('id')
            ->first(['id', 'sold_by', 'selling_price']);

        return $p !== null ? self::hit($p, null, null, null, 'product') : null;
    }

    /** @return array{prefixes: list<string>, item_digits: int, format: string, decimals: int} */
    public static function scaleSettings(?Company $company): array
    {
        $prefixes = array_values(array_filter(array_map(fn ($p) => preg_replace('/\D/', '', (string) $p), (array) StoreFeatures::setting($company, 'scale_prefixes')), fn ($p) => $p !== ''));

        return [
            'prefixes' => $prefixes,
            'item_digits' => max(1, min(8, (int) StoreFeatures::setting($company, 'scale_item_digits'))),
            'format' => StoreFeatures::setting($company, 'scale_format') === 'weight' ? 'weight' : 'price',
            'decimals' => max(0, min(4, (int) StoreFeatures::setting($company, 'scale_value_decimals'))),
        ];
    }

    /**
     * Decode an in-store scale label: EAN-13 = prefix + item code + value + check digit. The value fills
     * the digits between the item code and the check digit and is read with `decimals` places (weight
     * with 3 = grams → kg; price with the currency's decimals).
     *
     * @param  array{prefixes: list<string>, item_digits: int, format: string, decimals: int}  $s
     * @return array{item: string, value: float, kind: string}|null
     */
    public static function decodeScale(string $code, array $s): ?array
    {
        if (! preg_match('/^\d{13}$/', $code) || ! self::validEan13($code)) {
            return null;
        }
        foreach ($s['prefixes'] as $prefix) {
            if (! str_starts_with($code, $prefix)) {
                continue;
            }
            $valueDigits = 12 - strlen($prefix) - $s['item_digits'];
            if ($valueDigits < 1) {
                return null;
            }
            $item = substr($code, strlen($prefix), $s['item_digits']);
            $raw = (int) substr($code, strlen($prefix) + $s['item_digits'], $valueDigits);
            $value = round($raw / (10 ** $s['decimals']), 4);
            if ($value <= 0) {
                return null;
            }

            return ['item' => $item, 'value' => $value, 'kind' => $s['format']];
        }

        return null;
    }

    public static function validEan13(string $code): bool
    {
        if (! preg_match('/^\d{13}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $code[12];
    }

    /** The EAN-13 check digit for 12 digits (used to print scale labels and in tests). */
    public static function checkDigit(string $twelve): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $twelve[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /** A PLU "04011" on a label and "4011" typed are the same item. */
    private static function byPlu(int $cid, string $item): ?object
    {
        $trimmed = ltrim($item, '0');
        $codes = array_values(array_unique(array_filter([$item, $trimmed], fn ($c) => $c !== '')));
        if ($codes === []) {
            return null;
        }

        return DB::table('stock_items')->where('company_id', $cid)->whereIn('plu_code', $codes)->where('is_deleted', 0)->where('is_active', 1)
            ->orderBy('id')->first(['id', 'sold_by', 'selling_price']);
    }

    private static function hit(object $p, ?int $unitId, ?float $qty, ?float $price, string $source): array
    {
        $soldBy = in_array($p->sold_by ?? 'unit', self::SOLD_BY, true) ? (string) ($p->sold_by ?? 'unit') : 'unit';

        return [
            'stock_item_id' => (int) $p->id, 'unit_id' => $unitId, 'quantity' => $qty, 'price' => $price, 'sold_by' => $soldBy, 'source' => $source,
            // A typed PLU of something sold by weight/length/volume: the till asks how much.
            'needs_quantity' => $source === 'plu' && $soldBy !== 'unit',
        ];
    }
}

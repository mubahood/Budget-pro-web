<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price levels and quantity breaks (SUPERMARKET_PLAN.md B2), only with the `price_levels` feature on.
 *
 * A product may have prices per level (retail, wholesale, member or a shop's own name), per unit, each
 * from a minimum quantity ("1–11 at 1,000; 12+ at 900"). A line's catalogue price is the lowest price of
 * the customer's level or of retail whose minimum quantity the line reaches; with no such price it is the
 * product's selling price. A walk-in buys at retail, so quantity breaks work for everyone.
 *
 * Checkout (SaleService) prices a line without an explicit unit_price this way, and the till shows the
 * same price by calling price() / prices() too. Nothing here is read when the feature is off.
 */
class PriceLevelService
{
    public const LEVELS = ['retail' => 'Retail', 'wholesale' => 'Wholesale', 'member' => 'Member'];

    public const MAX_ROWS = 30;

    private static ?bool $available = null;

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('product_prices');
    }

    public static function enabled(Company|int|null $company): bool
    {
        $company = is_int($company) ? Company::withoutGlobalScopes()->find($company) : $company;

        return StoreFeatures::enabled($company, 'price_levels') && self::available();
    }

    /** The level a customer buys at (null = retail). */
    public static function levelOf(int $companyId, ?int $customerId): ?string
    {
        if (! $customerId || ! self::available()) {
            return null;
        }
        $level = DB::table('customers')->where('company_id', $companyId)->where('id', $customerId)->value('price_level');

        return $level !== null && trim((string) $level) !== '' ? (string) $level : null;
    }

    /**
     * The catalogue price of one line, per sold unit.
     */
    public static function price(int $companyId, int $productId, ?int $unitId, float $qty, ?string $level): float
    {
        $p = DB::table('stock_items')->where('company_id', $companyId)->where('id', $productId)->first(['selling_price']);
        $factor = $unitId ? max(0.001, (float) (DB::table('units')->where('company_id', $companyId)->where('id', $unitId)->value('factor') ?: 1)) : 1.0;
        $fallback = round((float) ($p->selling_price ?? 0) * $factor, 2);

        return self::pick(self::rowsFor($companyId, [$productId])[$productId] ?? [], $unitId, $factor, $qty, $level) ?? $fallback;
    }

    /**
     * Every level price of these products, one query. @return array<int, list<array{unit_id: ?int, level: string, price: float, min_qty: float}>>
     *
     * @param  list<int>  $productIds
     */
    public static function rowsFor(int $companyId, array $productIds): array
    {
        if ($productIds === [] || ! self::available()) {
            return [];
        }
        $out = [];
        foreach (DB::table('product_prices')->where('company_id', $companyId)->whereIn('stock_item_id', array_values(array_unique($productIds)))
            ->orderBy('min_qty')->get(['stock_item_id', 'unit_id', 'level', 'price', 'min_qty']) as $r) {
            $out[(int) $r->stock_item_id][] = ['unit_id' => $r->unit_id !== null ? (int) $r->unit_id : null, 'level' => (string) $r->level, 'price' => (float) $r->price, 'min_qty' => (float) $r->min_qty];
        }

        return $out;
    }

    /**
     * The lowest matching price (customer's level or retail, minimum quantity reached) per sold unit, or null
     * when none matches. Rows of the line's own unit come first; a pack unit without rows of its own uses the
     * product's base rows, counting its quantity in base units and multiplying the price by the pack factor.
     *
     * @param  list<array{unit_id: ?int, level: string, price: float, min_qty: float}>  $rows
     */
    public static function pick(array $rows, ?int $unitId, float $factor, float $qty, ?string $level): ?float
    {
        if ($rows === []) {
            return null;
        }
        $levels = array_unique([strtolower(trim((string) $level)) ?: 'retail', 'retail']);
        $match = function (?int $unit, float $q) use ($rows, $levels): ?float {
            $best = null;
            foreach ($rows as $r) {
                if ($r['unit_id'] === $unit && in_array(strtolower($r['level']), $levels, true) && $r['min_qty'] <= $q + 0.0005) {
                    $best = $best === null ? $r['price'] : min($best, $r['price']);
                }
            }

            return $best;
        };
        if ($unitId !== null && collect($rows)->contains(fn ($r) => $r['unit_id'] === $unitId)) {
            return ($p = $match($unitId, $qty)) !== null ? round($p, 2) : null;
        }
        $base = $match(null, $unitId !== null ? $qty * $factor : $qty);

        return $base !== null ? round($base * ($unitId !== null ? $factor : 1), 2) : null;
    }

    /** The levels a shop can choose from: retail, wholesale, member and the names it already uses. @return array<string, string> */
    public static function levels(int $companyId): array
    {
        $out = self::LEVELS;
        if (self::available()) {
            $used = DB::table('product_prices')->where('company_id', $companyId)->distinct()->pluck('level')
                ->merge(DB::table('customers')->where('company_id', $companyId)->whereNotNull('price_level')->distinct()->pluck('price_level'));
            foreach ($used as $l) {
                $key = strtolower(trim((string) $l));
                if ($key !== '' && ! isset($out[$key])) {
                    $out[$key] = ucfirst($key);
                }
            }
        }

        return $out;
    }

    /** A product's level prices, for the product screen. @return list<array{unit_id: ?int, unit: ?string, level: string, price: float, min_qty: float}> */
    public function forProduct(int $companyId, int $productId): array
    {
        if (! self::available()) {
            return [];
        }

        return DB::table('product_prices as pp')->leftJoin('units as u', 'u.id', '=', 'pp.unit_id')
            ->where('pp.company_id', $companyId)->where('pp.stock_item_id', $productId)
            ->orderBy('pp.level')->orderBy('pp.unit_id')->orderBy('pp.min_qty')
            ->get(['pp.unit_id', 'u.name as unit', 'pp.level', 'pp.price', 'pp.min_qty'])
            ->map(fn ($r) => ['unit_id' => $r->unit_id !== null ? (int) $r->unit_id : null, 'unit' => $r->unit, 'level' => (string) $r->level, 'price' => (float) $r->price, 'min_qty' => (float) $r->min_qty])
            ->all();
    }

    /**
     * Replace a product's level prices and quantity breaks.
     *
     * @param  list<array{level?: mixed, unit_id?: mixed, price?: mixed, min_qty?: mixed}>  $rows
     */
    public function save(int $companyId, int $productId, array $rows): array
    {
        if (! self::enabled($companyId)) {
            throw BusinessRuleException::make('feature_off', 'Price levels are off for this shop. Turn them on in Settings → Supermarket.');
        }
        if (! DB::table('stock_items')->where('company_id', $companyId)->where('id', $productId)->where('is_deleted', 0)->exists()) {
            throw BusinessRuleException::make('product_not_found', 'That product was not found.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw BusinessRuleException::make('too_many_prices', 'A product can have at most '.self::MAX_ROWS.' level prices.');
        }
        $clean = [];
        $seen = [];
        foreach (array_values($rows) as $i => $r) {
            $n = $i + 1;
            $level = strtolower(trim((string) ($r['level'] ?? '')));
            if ($level === '' || mb_strlen($level) > 40 || ! preg_match('/^[\pL\pN][\pL\pN \-_]*$/u', $level)) {
                throw BusinessRuleException::make('invalid_level', "Row {$n}: give the price level a short name (letters and numbers).");
            }
            if (! is_numeric($r['price'] ?? null) || (float) $r['price'] < 0 || (float) $r['price'] > PriceBookService::MAX_PRICE) {
                throw BusinessRuleException::make('invalid_price', "Row {$n}: enter a price of zero or more.");
            }
            $minQty = is_numeric($r['min_qty'] ?? null) ? round((float) $r['min_qty'], 3) : 1.0;
            if ($minQty <= 0 || $minQty > 1000000) {
                throw BusinessRuleException::make('invalid_min_qty', "Row {$n}: the quantity it starts from must be more than zero.");
            }
            $unitId = is_numeric($r['unit_id'] ?? null) && (int) $r['unit_id'] > 0 ? (int) $r['unit_id'] : null;
            if ($unitId !== null && ! DB::table('units')->where('company_id', $companyId)->where('id', $unitId)->exists()) {
                throw BusinessRuleException::make('unit_not_found', "Row {$n}: that unit was not found.");
            }
            $key = $level.'|'.($unitId ?? 0).'|'.$minQty;
            if (isset($seen[$key])) {
                throw BusinessRuleException::make('duplicate_price', "Row {$n}: the same level, unit and starting quantity is listed twice.");
            }
            $seen[$key] = true;
            $clean[] = ['company_id' => $companyId, 'stock_item_id' => $productId, 'unit_id' => $unitId, 'level' => $level, 'price' => round((float) $r['price'], 2),
                'min_qty' => $minQty, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::transaction(function () use ($companyId, $productId, $clean) {
            DB::table('product_prices')->where('company_id', $companyId)->where('stock_item_id', $productId)->delete();
            if ($clean !== []) {
                DB::table('product_prices')->insert($clean);
            }
        });

        return $this->forProduct($companyId, $productId);
    }
}

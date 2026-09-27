<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Support\StoreFeatures;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Local prices and availability per store (SUPERMARKET_PLAN.md G1, StoreFeatures `store_prices`).
 *
 * One product list for the company; each store (location) may have its own price for a product (null =
 * the usual selling price) and may not sell it at all. Checkout (SaleService) and the till price a line at
 * the sale's store through PriceLevelService (the store's price replaces the selling price before price
 * levels and promotions) and refuse a product the store does not sell.
 *
 * HQ can push one price to every store or to some: now, or from a date and time through the price book
 * (PriceBookService::schedule with the store) when the shop keeps a price book.
 */
class StorePriceService
{
    public const MAX_PRICE = PriceBookService::MAX_PRICE;

    public static function enabled(Company|int|null $company): bool
    {
        return PriceLevelService::storePricesOn($company);
    }

    /** The store a sale sells from: the one asked for, else the main location. */
    public static function saleLocation(int $companyId, array $data): int
    {
        return ! empty($data['location_id']) ? (int) $data['location_id'] : LocationStock::defaultLocation($companyId);
    }

    /**
     * Names of the products a store does not sell (empty when store prices are off).
     *
     * @param  list<int>  $productIds
     * @return list<string>
     */
    public static function unavailable(int $companyId, ?int $locationId, array $productIds): array
    {
        if (! $locationId || $productIds === [] || ! self::enabled($companyId)) {
            return [];
        }
        $off = array_keys(array_filter(PriceLevelService::locationRows($companyId, $locationId, $productIds), fn ($r) => ! $r['available']));

        return $off === [] ? [] : DB::table('stock_items')->whereIn('id', $off)->orderBy('name')->pluck('name')->map(fn ($n) => (string) $n)->all();
    }

    /** Refuse a sale of products the store does not sell, naming them and the store. */
    public static function assertAvailable(int $companyId, ?int $locationId, array $productIds): void
    {
        $names = self::unavailable($companyId, $locationId, $productIds);
        if ($names !== []) {
            $store = (string) DB::table('locations')->where('id', $locationId)->value('name');
            $list = implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' and '.(count($names) - 3).' more' : '');
            throw BusinessRuleException::make('not_sold_here', "{$list} ".(count($names) === 1 ? 'is' : 'are')." not sold at {$store}. Take ".(count($names) === 1 ? 'it' : 'them').' off the sale, or allow '.(count($names) === 1 ? 'it' : 'them').' at this store under the product\'s store prices.',
                ['location_id' => $locationId, 'products' => $names]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, object> the shop's open stores, the main one first */
    public function stores(int $companyId)
    {
        return DB::table('locations')->where('company_id', $companyId)->where('is_active', 1)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']);
    }

    /**
     * A product at every open store: its own price (null = usual), whether it sells it, and scheduled prices.
     *
     * @return list<array{location_id: int, name: string, is_default: bool, price: ?float, is_available: bool, scheduled: list<array{id: int, new: float, starts_at: string}>}>
     */
    public function forProduct(int $companyId, int $productId): array
    {
        $rows = DB::table('location_prices')->where('company_id', $companyId)->where('stock_item_id', $productId)->whereNull('unit_id')->get()->keyBy('location_id');
        $scheduled = PriceBookService::hasLocations() ? DB::table('price_changes')->where('company_id', $companyId)->where('stock_item_id', $productId)->whereNotNull('location_id')
            ->whereNull('applied_at')->whereNull('cancelled_at')->orderBy('starts_at')->get(['id', 'location_id', 'new', 'starts_at'])->groupBy('location_id') : collect();

        return $this->stores($companyId)->map(fn ($l) => [
            'location_id' => (int) $l->id, 'name' => (string) $l->name, 'is_default' => (bool) $l->is_default,
            'price' => isset($rows[$l->id]) && $rows[$l->id]->price !== null ? (float) $rows[$l->id]->price : null,
            'is_available' => ! isset($rows[$l->id]) || (bool) $rows[$l->id]->is_available,
            'scheduled' => ($scheduled[$l->id] ?? collect())->map(fn ($c) => ['id' => (int) $c->id, 'new' => (float) $c->new, 'starts_at' => (string) $c->starts_at])->values()->all(),
        ])->values()->all();
    }

    /**
     * Save a product's price and availability at each store listed (the product's own unit). A blank price
     * is the usual price; a store not listed keeps what it has.
     *
     * @param  list<array{location_id: mixed, price?: mixed, is_available?: mixed}>  $rows
     */
    public function save(int $companyId, int $productId, array $rows): array
    {
        $this->assertOn($companyId);
        $this->assertProduct($companyId, $productId);
        $stores = $this->stores($companyId)->keyBy('id');
        $clean = [];
        foreach (array_values($rows) as $r) {
            $loc = (int) ($r['location_id'] ?? 0);
            if (! isset($stores[$loc])) {
                throw BusinessRuleException::make('location_not_found', 'That store was not found, or it is closed.');
            }
            $price = $r['price'] ?? null;
            $price = $price === '' || $price === null ? null : $price;
            if ($price !== null && (! is_numeric($price) || (float) $price < 0 || (float) $price > self::MAX_PRICE)) {
                throw BusinessRuleException::make('invalid_price', "{$stores[$loc]->name}: enter a price of zero or more, or leave it blank for the usual price.");
            }
            $clean[$loc] = ['price' => $price === null ? null : round((float) $price, 2), 'is_available' => filter_var($r['is_available'] ?? true, FILTER_VALIDATE_BOOLEAN)];
        }
        DB::transaction(function () use ($companyId, $productId, $clean) {
            foreach ($clean as $loc => $c) {
                $this->write($companyId, $loc, $productId, null, $c['price'], $c['is_available']);
            }
        });

        return $this->forProduct($companyId, $productId);
    }

    /**
     * Push one price to every open store ($locationIds null) or to some: now, or from $startsAt (price book on).
     * Without the price book the price starts now.
     *
     * @param  list<int>|null  $locationIds
     * @return array{applied: int, scheduled: int}
     */
    public function push(int $companyId, int $userId, int $productId, float $price, ?array $locationIds = null, CarbonInterface|string|null $startsAt = null, ?string $reason = null): array
    {
        $this->assertOn($companyId);
        $this->assertProduct($companyId, $productId);
        if ($price < 0 || $price > self::MAX_PRICE || ! is_finite($price)) {
            throw BusinessRuleException::make('invalid_price', 'Enter a price of zero or more.');
        }
        $stores = $this->stores($companyId)->pluck('id')->map(fn ($id) => (int) $id);
        $targets = $locationIds === null ? $stores->all() : array_values(array_unique(array_map('intval', $locationIds)));
        if ($targets === []) {
            throw BusinessRuleException::make('no_stores', 'Choose at least one store.');
        }
        foreach ($targets as $loc) {
            if (! $stores->contains($loc)) {
                throw BusinessRuleException::make('location_not_found', 'That store was not found, or it is closed.');
            }
        }
        $company = Company::withoutGlobalScopes()->find($companyId);
        $book = StoreFeatures::enabled($company, 'price_book') && PriceBookService::hasLocations();
        $out = ['applied' => 0, 'scheduled' => 0];
        DB::transaction(function () use ($companyId, $userId, $productId, $price, $targets, $startsAt, $reason, $book, &$out) {
            foreach ($targets as $loc) {
                if ($book && $startsAt !== null && $startsAt !== '') {
                    (new PriceBookService())->schedule($companyId, $productId, 'selling', $price, $startsAt, $reason, $userId, null, $loc);
                    $out['scheduled']++;

                    continue;
                }
                $old = $this->setPrice($companyId, $loc, $productId, null, $price);
                if ($book) {
                    (new PriceBookService())->record($companyId, $productId, 'selling', $old, $price, $reason, $userId, null, $loc);
                }
                $out['applied']++;
            }
        });

        return $out;
    }

    /**
     * Set one store's price (availability kept). Returns the price the store charged before (its own, or the usual one).
     */
    public function setPrice(int $companyId, int $locationId, int $productId, ?int $unitId, float $price): float
    {
        $row = DB::table('location_prices')->where('company_id', $companyId)->where('location_id', $locationId)->where('stock_item_id', $productId)
            ->when($unitId === null, fn ($q) => $q->whereNull('unit_id'), fn ($q) => $q->where('unit_id', $unitId))->first();
        $old = $row && $row->price !== null ? (float) $row->price : (float) DB::table('stock_items')->where('id', $productId)->value('selling_price');
        $this->write($companyId, $locationId, $productId, $unitId, round($price, 2), $row ? (bool) $row->is_available : true);

        return $old;
    }

    /** One row per store, product and unit; a row that says nothing (usual price, sold) is removed. */
    private function write(int $companyId, int $locationId, int $productId, ?int $unitId, ?float $price, bool $available): void
    {
        $q = DB::table('location_prices')->where('company_id', $companyId)->where('location_id', $locationId)->where('stock_item_id', $productId)
            ->when($unitId === null, fn ($w) => $w->whereNull('unit_id'), fn ($w) => $w->where('unit_id', $unitId));
        if ($price === null && $available) {
            $q->delete();

            return;
        }
        $id = (clone $q)->orderBy('id')->value('id');
        if ($id) {
            DB::table('location_prices')->where('id', $id)->update(['price' => $price, 'is_available' => $available, 'updated_at' => now()]);
            (clone $q)->where('id', '!=', $id)->delete();

            return;
        }
        DB::table('location_prices')->insert(['company_id' => $companyId, 'location_id' => $locationId, 'stock_item_id' => $productId, 'unit_id' => $unitId,
            'price' => $price, 'is_available' => $available, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assertOn(int $companyId): void
    {
        if (! self::enabled($companyId)) {
            throw BusinessRuleException::make('feature_off', 'Store prices are off for this shop. Turn them on in Settings → Supermarket.');
        }
    }

    private function assertProduct(int $companyId, int $productId): void
    {
        if (! DB::table('stock_items')->where('company_id', $companyId)->where('id', $productId)->where('is_deleted', 0)->exists()) {
            throw BusinessRuleException::make('product_not_found', 'That product was not found.');
        }
    }
}

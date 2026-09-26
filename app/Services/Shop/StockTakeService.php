<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\StockItem;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use Illuminate\Support\Facades\DB;

/**
 * Stock count sessions (plan A4, P2-7): counts are recorded (on any device)
 * together with what the system showed at that moment; posting applies the
 * difference (counted − system at count time) as one adjustment per product.
 * Sales and deliveries between counting and posting are therefore kept, not
 * wiped. The latest count for a product wins.
 */
class StockTakeService
{
    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    /** Aisle counts (D3): a count differing from the system by more than this % (and at least one unit) is counted again. */
    public const RECOUNT_PCT = 10;

    /**
     * @param  ?string  $shelfLocation  count one aisle / shelf location (a prefix: "A3" covers "A3-B2"); only with `aisle_counts` on
     */
    public function create(int $companyId, int $userId, string $name, ?int $categoryId = null, ?string $clientUuid = null, ?string $deviceId = null, ?string $shelfLocation = null): StockTake
    {
        if ($clientUuid) {
            $existing = StockTake::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $clientUuid)->first();
            if ($existing) {
                return $existing;
            }
        }
        $take = new StockTake();
        if ($clientUuid) {
            $take->uuid = $clientUuid;
        }
        $take->company_id = $companyId;
        $take->number = NumberSequencer::next($companyId, 'stock_take');
        $take->name = trim($name) !== '' ? trim($name) : 'Stock count '.now()->format('d M Y');
        $take->stock_category_id = $categoryId;
        $take->status = 'draft';
        $take->device_id = $deviceId;
        $take->created_by_id = $userId;
        $shelf = self::normaliseShelf($shelfLocation);
        if ($shelf !== null && self::aisleCounts($companyId)) {
            $take->shelf_location = $shelf;
        }
        $take->save();

        return $take;
    }

    /** @param array<int, array{stock_item_id: int, counted_quantity: float|string}> $counts */
    public function count(StockTake $take, array $counts): StockTake
    {
        if ($take->status !== 'draft') {
            throw BusinessRuleException::make('stock_take_posted', 'This count has already been posted.');
        }
        $recounts = self::aisleCounts((int) $take->company_id);
        foreach ($counts as $c) {
            $qty = round((float) $c['counted_quantity'], 3);
            if ($qty < 0) {
                throw BusinessRuleException::make('invalid_quantity', 'Counted quantity cannot be negative.');
            }
            $product = StockItem::withoutGlobalScopes()->where('company_id', $take->company_id)->find($c['stock_item_id']);
            if ($product === null) {
                throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
            }
            $existing = StockTakeItem::withoutGlobalScopes()->where('stock_take_id', $take->id)->where('stock_item_id', $product->id)->first();
            if ($recounts) {
                $this->countWithRecount($take, $product, $existing, $qty);

                continue;
            }
            if ($existing !== null && round((float) $existing->counted_quantity, 3) === $qty) {
                continue; // unchanged count re-sent: keep the snapshot taken when it was counted
            }
            StockTakeItem::updateOrCreate(
                ['stock_take_id' => $take->id, 'stock_item_id' => $product->id],
                ['company_id' => $take->company_id, 'counted_quantity' => $qty, 'system_quantity' => $product->current_quantity]
            );
        }
        $take->saveQuietlySynced();

        return $take->load('items');
    }

    public function post(StockTake $take, int $userId): StockTake
    {
        if ($take->status === 'posted') {
            return $take->load('items');
        }
        if ($take->status !== 'draft') {
            throw BusinessRuleException::make('stock_take_cancelled', 'This count was cancelled.');
        }

        $waiting = StockTakeItem::withoutGlobalScopes()->where('stock_take_id', $take->id)->where('needs_recount', true)->count();
        if ($waiting > 0) { // only ever set with aisle_counts on
            throw BusinessRuleException::make('recount_needed', $waiting.' product'.($waiting > 1 ? 's differ' : ' differs').' a lot from the system. Count '.($waiting > 1 ? 'them' : 'it').' again before posting.', ['count' => $waiting]);
        }

        return DB::transaction(function () use ($take, $userId) {
            $take = StockTake::withoutGlobalScopes()->lockForUpdate()->find($take->id);
            foreach (StockTakeItem::withoutGlobalScopes()->where('stock_take_id', $take->id)->orderBy('stock_item_id')->get() as $item) {
                $product = StockService::lock((int) $item->stock_item_id);
                // Counts saved before the snapshot existed fall back to the live figure.
                $system = $item->system_quantity !== null ? round((float) $item->system_quantity, 3) : round((float) $product->current_quantity, 3);
                $delta = round((float) $item->counted_quantity - $system, 3);
                $item->system_quantity = $system;
                $item->delta = $delta;
                if ($delta != 0.0) {
                    $item->stock_record_id = $this->stock->record([
                        'stock_item_id' => $product->id, 'type' => $delta > 0 ? 'Adjustment In' : 'Adjustment Out', 'quantity' => abs($delta),
                        'description' => 'Stock count '.$take->number.': counted '.rtrim(rtrim(number_format((float) $item->counted_quantity, 3, '.', ''), '0'), '.'),
                        'created_by_id' => $userId, 'reference_type' => 'stock_take', 'reference_id' => $take->id, 'allow_negative' => true,
                    ])->id;
                }
                $item->save();
            }
            $take->status = 'posted';
            $take->posted_at = now();
            $take->posted_by_id = $userId;
            $take->save();

            return $take->load('items');
        });
    }

    public static function aisleCounts(int $companyId): bool
    {
        return \App\Support\StoreFeatures::enabled(\App\Models\Company::withoutGlobalScopes()->find($companyId), 'aisle_counts');
    }

    /** Does a count this far from the system need a second count? */
    public static function needsRecount(float $counted, float $system): bool
    {
        $diff = abs($counted - $system);

        return $diff >= 1 && $diff > abs($system) * self::RECOUNT_PCT / 100;
    }

    /**
     * Aisle counts: the first count far from the system is kept as `first_count` and flagged; the next
     * count of that product (the recount) clears the flag and is the one posted.
     */
    private function countWithRecount(StockTake $take, StockItem $product, ?StockTakeItem $existing, float $qty): void
    {
        if ($existing === null) {
            $system = round((float) $product->current_quantity, 3);
            $flag = self::needsRecount($qty, $system);
            StockTakeItem::create(['stock_take_id' => $take->id, 'stock_item_id' => $product->id, 'company_id' => $take->company_id, 'counted_quantity' => $qty,
                'system_quantity' => $product->current_quantity])->forceFill(['needs_recount' => $flag, 'first_count' => $flag ? $qty : null])->save();

            return;
        }
        if ($existing->needs_recount) { // the second count: it stands
            $existing->forceFill(['counted_quantity' => $qty, 'needs_recount' => false])->save();

            return;
        }
        if (round((float) $existing->counted_quantity, 3) === $qty) {
            return;
        }
        $system = round((float) $product->current_quantity, 3);
        $flag = self::needsRecount($qty, $system);
        $existing->forceFill(['counted_quantity' => $qty, 'system_quantity' => $product->current_quantity, 'needs_recount' => $flag, 'first_count' => $flag ? $qty : $existing->first_count])->save();
    }

    public static function normaliseShelf(?string $shelf): ?string
    {
        $shelf = $shelf === null ? '' : strtoupper(trim(preg_replace('/\s+/', ' ', $shelf)));

        return $shelf === '' ? null : mb_substr($shelf, 0, 40);
    }

    /** Set a product's shelf location (aisle / bay text; blank clears it). */
    public function setShelfLocation(int $companyId, int $stockItemId, ?string $shelf): StockItem
    {
        $item = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find($stockItemId);
        if ($item === null) {
            throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
        }
        $item->forceFill(['shelf_location' => self::normaliseShelf($shelf)])->save();

        return $item;
    }

    /** The shelf locations in use, for pickers. @return list<string> */
    public static function shelfLocations(int $companyId): array
    {
        return DB::table('stock_items')->where('company_id', $companyId)->where('is_deleted', 0)->whereNotNull('shelf_location')
            ->distinct()->orderBy('shelf_location')->limit(500)->pluck('shelf_location')->all();
    }

    /** Cancel a count that was never posted: nothing moves. A posted count stays posted. */
    public function cancel(StockTake $take): StockTake
    {
        if ($take->status === 'cancelled') {
            return $take;
        }
        if ($take->status !== 'draft') {
            throw BusinessRuleException::make('stock_take_posted', 'This count has already been posted. Count the products again to correct stock.');
        }
        $take->status = 'cancelled';
        $take->save();

        return $take;
    }
}

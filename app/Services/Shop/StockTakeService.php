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

    public function create(int $companyId, int $userId, string $name, ?int $categoryId = null, ?string $clientUuid = null, ?string $deviceId = null): StockTake
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
        $take->save();

        return $take;
    }

    /** @param array<int, array{stock_item_id: int, counted_quantity: float|string}> $counts */
    public function count(StockTake $take, array $counts): StockTake
    {
        if ($take->status !== 'draft') {
            throw BusinessRuleException::make('stock_take_posted', 'This count has already been posted.');
        }
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

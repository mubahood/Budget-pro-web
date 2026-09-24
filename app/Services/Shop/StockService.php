<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\StockItem;
use App\Models\StockRecord;
use Illuminate\Support\Facades\DB;

/**
 * The only way stock moves (P0-4/P0-5, plan A2 + B4).
 *
 * Every movement is an append-only StockRecord carrying a signed
 * `quantity_delta`; StockItem.current_quantity is a cache maintained with an
 * atomic `UPDATE ... SET current_quantity = current_quantity + delta` under a
 * row lock, never a read-modify-write. Corrections are new (reversal) rows.
 */
class StockService
{
    public const INBOUND = ['Stock In', 'Purchase', 'Return', 'Adjustment In', 'Opening', 'Transfer In'];

    public const OUTBOUND = ['Sale', 'Damage', 'Expired', 'Lost', 'Internal Use', 'Adjustment Out', 'Other', 'Stock Out', 'Transfer Out'];

    public static function types(): array
    {
        return array_merge(self::INBOUND, self::OUTBOUND);
    }

    public static function isInbound(string $type): bool
    {
        return in_array($type, self::INBOUND, true);
    }

    public static function assertValidType(?string $type): void
    {
        if ($type === null || ! in_array($type, self::types(), true)) {
            throw BusinessRuleException::make('invalid_movement_type', 'Unknown stock movement type "'.$type.'".', ['allowed' => self::types()]);
        }
    }

    /**
     * Record one movement. Idempotent on (company_id, client_uuid).
     *
     * @param  array{stock_item_id:int, type:string, quantity:float|string, description?:string|null, date?:string|\DateTimeInterface|null,
     *               selling_price?:float|null, unit_cost?:float|null, created_by_id?:int|null, client_uuid?:string|null,
     *               reference_type?:string|null, reference_id?:int|null, sale_record_id?:int|null, allow_negative?:bool}  $attrs
     */
    public function record(array $attrs): StockRecord
    {
        if (! empty($attrs['client_uuid'])) {
            $item = StockItem::withoutGlobalScopes()->find($attrs['stock_item_id']);
            $existing = StockRecord::withoutGlobalScopes()
                ->where('company_id', $item?->company_id)
                ->where('client_uuid', $attrs['client_uuid'])
                ->first();
            if ($existing) {
                $existing->wasReplayed = true;

                return $existing;
            }
        }

        $record = new StockRecord();
        foreach (['stock_item_id', 'type', 'quantity', 'description', 'selling_price', 'unit_cost', 'created_by_id', 'client_uuid', 'reference_type', 'reference_id', 'sale_record_id', 'reason', 'image'] as $key) {
            if (array_key_exists($key, $attrs) && $attrs[$key] !== null) {
                $record->{$key} = $attrs[$key];
            }
        }
        if (! empty($attrs['date'])) {
            $record->date = $attrs['date'] instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($attrs['date']) : \Illuminate\Support\Carbon::parse($attrs['date']);
        }
        $record->allowNegative = (bool) ($attrs['allow_negative'] ?? false);
        $record->save();

        return $record;
    }

    /** Contra movement that undoes $record (stock, price, profit), keeping both rows. */
    public function reverse(StockRecord $record, ?string $reason = null, ?int $userId = null): StockRecord
    {
        if ($record->is_reversal) {
            throw BusinessRuleException::make('already_reversal', 'A reversal cannot itself be reversed.');
        }
        $already = StockRecord::withoutGlobalScopes()->where('reverses_id', $record->id)->first();
        if ($already) {
            return $already;
        }

        return DB::transaction(function () use ($record, $reason, $userId) {
            $contra = new StockRecord();
            $contra->stock_item_id = $record->stock_item_id;
            $contra->type = $record->type;
            $contra->quantity = $record->quantity;
            $contra->selling_price = $record->selling_price;
            $contra->unit_cost = $record->unit_cost ?? $record->buying_price;
            $contra->description = 'Reversal of #'.$record->id.($reason ? ': '.$reason : '');
            $contra->date = now();
            $contra->created_by_id = $userId ?? $record->created_by_id;
            $contra->reference_type = $record->reference_type;
            $contra->reference_id = $record->reference_id;
            $contra->sale_record_id = $record->sale_record_id;
            $contra->is_reversal = true;
            $contra->reverses_id = $record->id;
            // Undoing an outbound movement puts stock back (always safe); undoing an inbound one removes
            // stock and must respect the product's negative-stock policy like any other movement.
            $contra->allowNegative = ! self::isInbound((string) $record->type);
            $contra->save();

            return $contra;
        });
    }

    /** Lock a product row for the rest of the current transaction and return it fresh. */
    public static function lock(int $stockItemId): StockItem
    {
        $item = StockItem::withoutGlobalScopes()->lockForUpdate()->find($stockItemId);
        if ($item === null) {
            throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
        }

        return $item;
    }
}

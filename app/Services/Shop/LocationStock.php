<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\StockRecord;
use Illuminate\Support\Facades\DB;

/**
 * Stock per location and per batch (plan P4-4, decision H6). Called from the
 * StockRecord hooks, so every movement — API, web, sync, legacy — keeps
 * stock_levels and batches in step with the product's total.
 *
 * Batches: an inbound movement adds to a batch (batch number + expiry from the
 * delivery, or "NO-BATCH"); an outbound one takes from batches First-Expiry-
 * First-Out; a reversal gives back exactly what the original took.
 */
class LocationStock
{
    public const NO_BATCH = 'NO-BATCH';

    /** @var array<int, int> */
    private static array $defaults = [];

    public static function defaultLocation(int $companyId): int
    {
        if (isset(self::$defaults[$companyId])) {
            return self::$defaults[$companyId];
        }
        $id = DB::table('locations')->where('company_id', $companyId)->where('is_default', true)->value('id');
        if (! $id) {
            $id = DB::table('locations')->where('company_id', $companyId)->orderBy('id')->value('id')
                ?? DB::table('locations')->insertGetId(['company_id' => $companyId, 'name' => 'Main shop', 'is_default' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('locations')->where('id', $id)->update(['is_default' => true]);
        }

        return self::$defaults[$companyId] = (int) $id;
    }

    public static function flush(): void
    {
        self::$defaults = [];
    }

    public static function level(int $locationId, int $stockItemId): float
    {
        return (float) DB::table('stock_levels')->where('location_id', $locationId)->where('stock_item_id', $stockItemId)->value('quantity');
    }

    public static function adjust(int $companyId, int $locationId, int $stockItemId, float $delta): void
    {
        DB::statement('INSERT INTO stock_levels (company_id, location_id, stock_item_id, quantity, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()', [$companyId, $locationId, $stockItemId, round($delta, 3)]);
    }

    /** After a movement row is written: location level, then batches for batch-tracked products. */
    public static function applied(StockRecord $record): void
    {
        $delta = (float) $record->quantity_delta;
        self::adjust((int) $record->company_id, (int) $record->location_id, (int) $record->stock_item_id, $delta);
        if (! DB::table('stock_items')->where('id', $record->stock_item_id)->value('track_batches')) {
            return;
        }
        if ($record->is_reversal && $record->reverses_id) {
            foreach (DB::table('stock_record_batches')->where('stock_record_id', $record->reverses_id)->get() as $a) {
                DB::table('stock_batches')->where('id', $a->stock_batch_id)->update(['quantity' => DB::raw('quantity - ('.(float) $a->quantity.')'), 'updated_at' => now()]);
                self::link($record->id, (int) $a->stock_batch_id, -(float) $a->quantity);
            }

            return;
        }
        if ($delta > 0) {
            $parts = $record->batchIn ?: [['batch_number' => self::NO_BATCH, 'expiry_date' => null, 'quantity' => $delta]];
            // Stock sold while the location was below zero was never in a batch: this delivery covers that first.
            $debt = max(0.0, $delta - self::level((int) $record->location_id, (int) $record->stock_item_id));
            foreach ($parts as $i => $p) {
                $cover = min($debt, (float) $p['quantity']);
                $parts[$i]['quantity'] = round((float) $p['quantity'] - $cover, 3);
                $debt = round($debt - $cover, 3);
            }
            foreach (array_filter($parts, fn ($p) => (float) $p['quantity'] > 0) as $p) {
                $batch = self::batch((int) $record->company_id, (int) $record->stock_item_id, (int) $record->location_id, (string) ($p['batch_number'] ?: self::NO_BATCH), $p['expiry_date'] ?? null, $record->unit_cost);
                DB::table('stock_batches')->where('id', $batch)->update(['quantity' => DB::raw('quantity + '.(float) $p['quantity']), 'updated_at' => now()]);
                self::link($record->id, $batch, (float) $p['quantity']);
            }

            return;
        }
        // Outbound: First-Expiry-First-Out; anything beyond the batches on hand stays unallocated.
        $need = -$delta;
        $batches = DB::table('stock_batches')->where('stock_item_id', $record->stock_item_id)->where('location_id', $record->location_id)->where('quantity', '>', 0)
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')->lockForUpdate()->get();
        foreach ($batches as $b) {
            if ($need <= 0) {
                break;
            }
            $take = min($need, (float) $b->quantity);
            DB::table('stock_batches')->where('id', $b->id)->update(['quantity' => DB::raw('quantity - '.$take), 'updated_at' => now()]);
            self::link($record->id, (int) $b->id, -$take);
            $need = round($need - $take, 3);
        }
    }

    private static function batch(int $companyId, int $stockItemId, int $locationId, string $number, ?string $expiry, mixed $cost): int
    {
        $id = DB::table('stock_batches')->where('stock_item_id', $stockItemId)->where('location_id', $locationId)->where('batch_number', $number)->value('id');
        if ($id) {
            if ($expiry) {
                DB::table('stock_batches')->where('id', $id)->whereNull('expiry_date')->update(['expiry_date' => $expiry]);
            }

            return (int) $id;
        }

        return (int) DB::table('stock_batches')->insertGetId(['company_id' => $companyId, 'stock_item_id' => $stockItemId, 'location_id' => $locationId, 'batch_number' => $number,
            'expiry_date' => $expiry, 'quantity' => 0, 'unit_cost' => $cost, 'created_at' => now(), 'updated_at' => now()]);
    }

    private static function link(int $recordId, int $batchId, float $qty): void
    {
        DB::table('stock_record_batches')->insert(['stock_record_id' => $recordId, 'stock_batch_id' => $batchId, 'quantity' => round($qty, 3), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Batches a movement took from (for transfers: the same batches arrive at the other location). */
    public static function taken(int $recordId): array
    {
        return DB::table('stock_record_batches as a')->join('stock_batches as b', 'b.id', '=', 'a.stock_batch_id')->where('a.stock_record_id', $recordId)
            ->get(['b.batch_number', 'b.expiry_date', 'a.quantity'])->map(fn ($r) => ['batch_number' => $r->batch_number, 'expiry_date' => $r->expiry_date, 'quantity' => -(float) $r->quantity])->all();
    }

    public static function assertLocation(int $companyId, int $locationId): void
    {
        if (! DB::table('locations')->where('company_id', $companyId)->where('id', $locationId)->where('is_active', true)->exists()) {
            throw BusinessRuleException::make('location_not_found', 'Location not found.');
        }
    }
}

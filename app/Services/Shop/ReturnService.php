<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\SaleRecord;
use App\Models\SaleRecordItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Support\Facades\DB;

/**
 * Returns & refunds with ledger symmetry (plan A1/A3, P2-6): returned lines put
 * stock back (optional per line), the sale's net value drops, and only money the
 * customer had actually paid beyond the new net is refunded — as a negative
 * payment with a contra Income row. Credit sales simply owe less.
 */
class ReturnService
{
    public function __construct(
        private readonly StockService $stock = new StockService(),
        private readonly PaymentService $payments = new PaymentService(),
    ) {
    }

    /**
     * @param  array<int, array{sale_item_id: int, quantity: float|string, restock?: bool}>  $lines
     */
    public function create(SaleRecord $sale, array $lines, int $userId, ?string $reason = null, string $refundMethod = 'cash', ?string $clientUuid = null, ?int $shiftId = null): SaleReturn
    {
        if ($clientUuid) {
            $existing = SaleReturn::withoutGlobalScopes()->where('company_id', $sale->company_id)->where('uuid', $clientUuid)->first();
            if ($existing) {
                return $existing->load('items');
            }
        }
        if ($sale->voided_at !== null) {
            throw BusinessRuleException::make('sale_voided', 'This sale was voided; there is nothing to return.');
        }
        if ($lines === []) {
            throw BusinessRuleException::make('empty_return', 'Choose at least one item to return.');
        }

        return DB::transaction(function () use ($sale, $lines, $userId, $reason, $refundMethod, $clientUuid, $shiftId) {
            $sale = SaleRecord::withoutGlobalScopes()->lockForUpdate()->find($sale->id);
            $return = new SaleReturn();
            if ($clientUuid) {
                $return->uuid = $clientUuid;
            }
            $return->company_id = $sale->company_id;
            $return->sale_record_id = $sale->id;
            $return->reason = $reason;
            $return->refund_method = \App\Models\Payment::normalizeMethod($refundMethod);
            $return->shift_id = $shiftId;
            $return->created_by_id = $userId;
            $return->save();

            $value = 0.0;
            foreach ($lines as $l) {
                /** @var SaleRecordItem|null $item */
                $item = SaleRecordItem::withoutGlobalScopes()->where('sale_record_id', $sale->id)->lockForUpdate()->find($l['sale_item_id']);
                if ($item === null) {
                    throw BusinessRuleException::make('line_not_found', 'That item is not part of this sale.');
                }
                $qty = round((float) $l['quantity'], 3);
                $returnable = round((float) $item->quantity - (float) $item->returned_quantity, 3);
                if ($qty <= 0 || $qty > $returnable) {
                    throw BusinessRuleException::make('invalid_return_quantity', "You can return at most {$returnable} of {$item->item_name}.", ['returnable' => $returnable]);
                }
                $lineValue = round((float) $item->line_total * $qty / max((float) $item->quantity, 0.001), 2);
                $restock = (bool) ($l['restock'] ?? true);
                $movementId = null;
                $product = \App\Models\StockItem::withoutGlobalScopes()->find($item->stock_item_id);
                if ($restock && $product && $product->track_stock !== false) {
                    $baseQty = round($qty * max((float) ($item->unit_factor ?: 1), 0.001), 3);
                    $movementId = $this->stock->record([
                        'stock_item_id' => (int) $item->stock_item_id, 'type' => 'Return', 'quantity' => $baseQty,
                        'unit_cost' => (float) $product->buying_price, 'description' => 'Return on sale '.($sale->receipt_number ?: '#'.$sale->id).($reason ? ': '.$reason : ''),
                        'created_by_id' => $userId, 'reference_type' => 'sale_return', 'reference_id' => $return->id, 'sale_record_id' => $sale->id,
                    ])->id;
                }
                // The line's profit shrinks with what came back.
                $item->profit = round((float) $item->profit - (float) $item->profit * $qty / max($returnable, 0.001), 2);
                $item->returned_quantity = round((float) $item->returned_quantity + $qty, 3);
                $item->saveQuietlySynced();

                SaleReturnItem::create([
                    'company_id' => $sale->company_id, 'sale_return_id' => $return->id, 'sale_record_item_id' => $item->id, 'stock_item_id' => $item->stock_item_id,
                    'quantity' => $qty, 'value' => $lineValue, 'restock' => $restock, 'stock_record_id' => $movementId,
                ]);
                $value += $lineValue;
            }

            $value = round($value, 2);
            $sale->refunded_amount = round((float) $sale->refunded_amount + $value, 2);
            $sale->saveQuietlySynced();

            // Refund only what was actually paid beyond the reduced net (money taken at the till on
            // sales from before payment rows existed counts as paid).
            $this->payments->adoptPaidAtSale($sale);
            $net = max(0, round((float) $sale->total_amount - (float) $sale->refunded_amount, 2));
            $paid = round((float) \App\Models\Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->sum('amount'), 2);
            $refund = max(0, round($paid - $net, 2));
            if ($refund > 0) {
                $this->payments->refund($sale, $refund, $refundMethod, $userId, $shiftId, $clientUuid ? \App\Support\Sync\SyncSequence::childUuid($clientUuid, 'refund') : null);
            }
            $this->payments->syncSaleTotals($sale->fresh());
            if ($sale->customer_id) {
                (new CustomerService())->recalc((int) $sale->customer_id);
            }

            $return->value = $value;
            $return->refund_amount = $refund;
            $return->saveQuietlySynced();

            return $return->load('items');
        });
    }
}

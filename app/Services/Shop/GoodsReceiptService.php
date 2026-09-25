<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialRecord;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\StockItem;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receiving stock (GRN-lite, plan A4/A5, P2-7): purchase movements at cost, the
 * product's buying price follows the latest cost, what was paid is a Purchase
 * expense, what wasn't becomes the supplier's balance.
 */
class GoodsReceiptService
{
    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    /**
     * @param  array<int, array{stock_item_id: int, quantity: float|string, unit_cost: float|string, purchase_order_item_id?: int|null, expected_unit_cost?: float|string|null, batch_number?: string|null, expiry_date?: string|null}>  $lines
     */
    public function receive(int $companyId, int $userId, array $lines, ?int $supplierId = null, ?string $invoiceRef = null, float $amountPaid = 0, string $paymentMethod = 'cash', ?string $receivedOn = null, ?string $clientUuid = null, ?string $notes = null, ?string $deviceId = null, ?int $purchaseOrderId = null, ?int $locationId = null): GoodsReceipt
    {
        if ($clientUuid) {
            $existing = GoodsReceipt::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $clientUuid)->first();
            if ($existing) {
                return $existing->load('items');
            }
        }
        if ($lines === []) {
            throw BusinessRuleException::make('empty_receipt', 'Add at least one product.');
        }
        if ($supplierId && ! Supplier::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($supplierId)->exists()) {
            throw BusinessRuleException::make('supplier_not_found', 'Supplier not found.');
        }

        return DB::transaction(function () use ($companyId, $userId, $lines, $supplierId, $invoiceRef, $amountPaid, $paymentMethod, $receivedOn, $clientUuid, $notes, $deviceId, $purchaseOrderId, $locationId) {
            if ($locationId) {
                LocationStock::assertLocation($companyId, $locationId);
            }
            $grn = new GoodsReceipt();
            if ($clientUuid) {
                $grn->uuid = $clientUuid;
            }
            $grn->company_id = $companyId;
            $grn->number = NumberSequencer::next($companyId, 'goods_receipt');
            $grn->supplier_id = $supplierId;
            $grn->invoice_ref = $invoiceRef;
            $grn->received_on = $receivedOn ? Carbon::parse($receivedOn) : now();
            $grn->payment_method = \App\Models\Payment::normalizeMethod($paymentMethod);
            $grn->notes = $notes;
            $grn->device_id = $deviceId;
            $grn->created_by_id = $userId;
            $grn->purchase_order_id = $purchaseOrderId;
            $grn->save();

            $total = 0.0;
            foreach ($lines as $l) {
                $qty = round((float) $l['quantity'], 3);
                $cost = round((float) $l['unit_cost'], 2);
                if ($qty <= 0 || $cost < 0) {
                    throw BusinessRuleException::make('invalid_line', 'Quantity must be positive and cost cannot be negative.');
                }
                $product = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->find($l['stock_item_id']);
                if ($product === null) {
                    throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
                }
                $movement = $this->stock->record([
                    'stock_item_id' => $product->id, 'type' => 'Purchase', 'quantity' => $qty, 'unit_cost' => $cost,
                    'description' => 'Received '.$grn->number.($invoiceRef ? ' (inv '.$invoiceRef.')' : ''), 'created_by_id' => $userId,
                    'reference_type' => 'goods_receipt', 'reference_id' => $grn->id, 'date' => $grn->received_on, 'location_id' => $locationId,
                    'batch_in' => ! empty($l['batch_number']) ? [['batch_number' => (string) $l['batch_number'], 'expiry_date' => $l['expiry_date'] ?? null, 'quantity' => $qty]] : [],
                ]);
                if ((float) $product->buying_price !== $cost) {
                    DB::table('stock_items')->where('id', $product->id)->update([
                        'buying_price' => $cost, 'server_seq' => \App\Support\Sync\SyncSequence::next(), 'version' => DB::raw('version + 1'), 'updated_at' => now(),
                    ]);
                }
                GoodsReceiptItem::create(['company_id' => $companyId, 'goods_receipt_id' => $grn->id, 'stock_item_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost, 'stock_record_id' => $movement->id,
                    'purchase_order_item_id' => $l['purchase_order_item_id'] ?? null, 'expected_unit_cost' => isset($l['expected_unit_cost']) ? round((float) $l['expected_unit_cost'], 2) : null,
                    'batch_number' => $l['batch_number'] ?? null, 'expiry_date' => $l['expiry_date'] ?? null]);
                $total += $qty * $cost;
            }
            $grn->total_cost = round($total, 2);
            $grn->amount_paid = min(round(max($amountPaid, 0), 2), $grn->total_cost);
            $grn->saveQuietlySynced();

            if ((float) $grn->amount_paid > 0) {
                $row = new FinancialRecord();
                $row->financial_category_id = SupplierService::purchaseCategory($companyId)->id;
                $row->company_id = $companyId;
                $row->user_id = $userId;
                $row->created_by_id = $userId;
                $row->amount = $grn->amount_paid;
                $row->quantity = 1;
                $row->type = 'Expense';
                $row->payment_method = $grn->payment_method;
                $row->recipient = $supplierId ? (string) Supplier::withoutGlobalScopes()->find($supplierId)?->name : '';
                $row->receipt = $invoiceRef ?? $grn->number;
                $row->date = $grn->received_on;
                $row->description = 'Stock purchase '.$grn->number;
                $row->source_type = 'goods_receipt';
                $row->source_id = $grn->id;
                $row->currency = Company::withoutGlobalScopes()->find($companyId)?->currency;
                $row->save();
            }
            if ($supplierId) {
                (new SupplierService())->recalc($supplierId);
            }

            return $grn->load('items');
        });
    }
}

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
     * A blank unit_cost means "same as the product's buying price"; the buying price follows a
     * new cost only when that cost is above zero (free goods never wipe the product's cost).
     *
     * Supermarket options (SUPERMARKET_PLAN.md D7/D8, each off unless the shop has the feature):
     *  - landed_costs: [{label, amount}] and landed_split 'value'|'quantity' (`landed_cost`): spread over the
     *    lines; the landed unit cost values the stock and becomes the product's cost; the extras are a paid
     *    stock expense of this delivery (not owed to the supplier);
     *  - products consigned from this supplier (`consignment`) arrive owing nothing (consignment_value);
     *  - the supplier's price list follows each cost received (`supplier_prices`).
     *
     * @param  array<int, array{stock_item_id: int, quantity: float|string, unit_cost?: float|string|null, purchase_order_item_id?: int|null, expected_unit_cost?: float|string|null, batch_number?: string|null, expiry_date?: string|null}>  $lines
     * @param  array{landed_costs?: list<array{label?: string, amount: float|string}>, landed_split?: string}  $options
     */
    public function receive(int $companyId, int $userId, array $lines, ?int $supplierId = null, ?string $invoiceRef = null, float $amountPaid = 0, string $paymentMethod = 'cash', ?string $receivedOn = null, ?string $clientUuid = null, ?string $notes = null, ?string $deviceId = null, ?int $purchaseOrderId = null, ?int $locationId = null, array $options = []): GoodsReceipt
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

        return DB::transaction(function () use ($companyId, $userId, $lines, $supplierId, $invoiceRef, $amountPaid, $paymentMethod, $receivedOn, $clientUuid, $notes, $deviceId, $purchaseOrderId, $locationId, $options) {
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
            $landed = LandedCost::forReceipt($companyId, $lines, $options);
            $consigned = 0.0;
            $consignOn = $supplierId && ConsignmentService::on($companyId);
            $received = []; // stock item id => cost, for the supplier's price list
            foreach ($lines as $key => $l) {
                $qty = round((float) $l['quantity'], 3);
                $product = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->find($l['stock_item_id']);
                if ($product === null) {
                    throw BusinessRuleException::make('product_not_found', 'Stock item not found.');
                }
                $rawCost = $l['unit_cost'] ?? null;
                $cost = ($rawCost === null || (is_string($rawCost) && trim($rawCost) === ''))
                    ? round((float) $product->buying_price, 2)
                    : round((float) $rawCost, 2);
                if ($qty <= 0 || $cost < 0) {
                    throw BusinessRuleException::make('invalid_line', 'Quantity must be positive and cost cannot be negative.');
                }
                $unitCost = $landed !== null ? round($cost + ($landed['per_unit'][$key] ?? 0), 2) : $cost;
                $isConsigned = $consignOn && (int) ($product->consignment_supplier_id ?? 0) === (int) $supplierId;
                $movement = $this->stock->record([
                    'stock_item_id' => $product->id, 'type' => 'Purchase', 'quantity' => $qty, 'unit_cost' => $unitCost,
                    'description' => 'Received '.$grn->number.($invoiceRef ? ' (inv '.$invoiceRef.')' : ''), 'created_by_id' => $userId,
                    'reference_type' => 'goods_receipt', 'reference_id' => $grn->id, 'date' => $grn->received_on, 'location_id' => $locationId,
                    'batch_in' => ! empty($l['batch_number']) ? [['batch_number' => (string) $l['batch_number'], 'expiry_date' => $l['expiry_date'] ?? null, 'quantity' => $qty]] : [],
                ]);
                if ($unitCost > 0 && round((float) $product->buying_price, 2) !== $unitCost) {
                    DB::table('stock_items')->where('id', $product->id)->update([
                        'buying_price' => $unitCost, 'server_seq' => \App\Support\Sync\SyncSequence::next(), 'version' => DB::raw('version + 1'), 'updated_at' => now(),
                    ]);
                }
                $item = new GoodsReceiptItem(['company_id' => $companyId, 'goods_receipt_id' => $grn->id, 'stock_item_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost, 'stock_record_id' => $movement->id,
                    'purchase_order_item_id' => $l['purchase_order_item_id'] ?? null, 'expected_unit_cost' => isset($l['expected_unit_cost']) ? round((float) $l['expected_unit_cost'], 2) : null,
                    'batch_number' => $l['batch_number'] ?? null, 'expiry_date' => $l['expiry_date'] ?? null]);
                $item->forceFill(($landed !== null ? ['landed_unit_cost' => round($cost + ($landed['per_unit'][$key] ?? 0), 4)] : []) + ($isConsigned ? ['is_consignment' => true] : []));
                $item->save();
                if ($isConsigned) {
                    $consigned += $qty * $cost; // supplier-owned until sold: nothing owed now
                } else {
                    $total += $qty * $cost;
                }
                if ($cost > 0) {
                    $received[(int) $product->id] = $cost;
                }
            }
            $grn->total_cost = round($total, 2);
            if ($landed !== null) {
                $grn->landed_costs = json_encode($landed['costs']);
                $grn->landed_cost_total = $landed['total'];
                $grn->landed_split = $landed['split'];
            }
            if ($consigned > 0) {
                $grn->consignment_value = round($consigned, 2);
            }
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
            if ($landed !== null) {
                $this->recordLandedCosts($grn, $landed['costs'], $landed['total'], $userId);
            }
            if ($supplierId && $received !== [] && SupplierPriceService::on($companyId)) {
                $prices = new SupplierPriceService();
                foreach ($received as $itemId => $cost) {
                    $prices->record($companyId, $supplierId, $itemId, $cost, $grn->received_on->toDateString(), null, 'receipt', (int) $grn->id, $userId);
                }
            }
            if ($supplierId) {
                (new SupplierService())->recalc($supplierId);
            }

            return $grn->load('items');
        });
    }

    /**
     * Landed cost extras (transport, duty, handling) are paid on delivery: one Purchase expense for the
     * delivery (source goods_receipt, so it counts with stock purchases, not running expenses).
     *
     * @param  list<array{label: string, amount: float}>  $costs
     */
    private function recordLandedCosts(GoodsReceipt $grn, array $costs, float $total, int $userId): void
    {
        $row = new FinancialRecord();
        $row->financial_category_id = SupplierService::purchaseCategory((int) $grn->company_id)->id;
        $row->company_id = $grn->company_id;
        $row->user_id = $userId;
        $row->created_by_id = $userId;
        $row->amount = $total;
        $row->quantity = 1;
        $row->type = 'Expense';
        $row->payment_method = $grn->payment_method;
        $row->recipient = implode(', ', array_column($costs, 'label'));
        $row->receipt = $grn->number;
        $row->date = $grn->received_on;
        $row->description = 'Delivery costs '.$grn->number.' ('.implode(', ', array_column($costs, 'label')).')';
        $row->source_type = 'goods_receipt';
        $row->source_id = $grn->id;
        $row->currency = Company::withoutGlobalScopes()->find($grn->company_id)?->currency;
        $row->save();
    }
}

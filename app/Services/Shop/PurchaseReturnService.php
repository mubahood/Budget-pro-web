<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialRecord;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockItem;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Returns to a supplier (plan A5, P4-1). */
class PurchaseReturnService
{
    /**
     * @param  array<int, array{stock_item_id: int, quantity: float|string, unit_cost?: float|string|null}>  $lines
     */
    public function create(int $companyId, int $userId, array $lines, ?int $supplierId, ?string $reason = null, float $refundAmount = 0, string $refundMethod = 'cash', ?int $goodsReceiptId = null, ?string $returnedOn = null): PurchaseReturn
    {
        if ($lines === []) {
            throw BusinessRuleException::make('empty_return', 'Add at least one product.');
        }
        if ($supplierId && ! Supplier::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($supplierId)->exists()) {
            throw BusinessRuleException::make('supplier_not_found', 'Supplier not found.');
        }

        return DB::transaction(function () use ($companyId, $userId, $lines, $supplierId, $reason, $refundAmount, $refundMethod, $goodsReceiptId, $returnedOn) {
            $ret = new PurchaseReturn();
            $ret->company_id = $companyId;
            $ret->number = NumberSequencer::next($companyId, 'purchase_return');
            $ret->supplier_id = $supplierId;
            $ret->goods_receipt_id = $goodsReceiptId;
            $ret->returned_on = $returnedOn ? Carbon::parse($returnedOn) : now();
            $ret->reason = $reason;
            $ret->refund_method = \App\Models\Payment::normalizeMethod($refundMethod);
            $ret->created_by_id = $userId;
            $ret->save();
            $total = 0.0;
            foreach ($lines as $l) {
                $qty = round((float) $l['quantity'], 3);
                $product = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->find($l['stock_item_id']);
                if ($product === null || $qty <= 0) {
                    throw BusinessRuleException::make('invalid_line', 'Choose a product and a quantity greater than zero.');
                }
                $cost = isset($l['unit_cost']) && $l['unit_cost'] !== '' ? round((float) $l['unit_cost'], 2) : round((float) $product->buying_price, 2);
                $movement = (new StockService())->record([
                    'stock_item_id' => $product->id, 'type' => 'Purchase Return', 'quantity' => $qty, 'unit_cost' => $cost, 'created_by_id' => $userId,
                    'description' => 'Returned to supplier '.$ret->number.($reason ? ": {$reason}" : ''), 'reference_type' => 'purchase_return', 'reference_id' => $ret->id,
                    'date' => $ret->returned_on,
                ]);
                PurchaseReturnItem::create(['company_id' => $companyId, 'purchase_return_id' => $ret->id, 'stock_item_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost, 'stock_record_id' => $movement->id]);
                $total += $qty * $cost;
            }
            $ret->total_value = round($total, 2);
            $ret->refund_amount = min(round(max($refundAmount, 0), 2), $ret->total_value);
            $ret->save();

            if ((float) $ret->refund_amount > 0) {
                $row = new FinancialRecord();
                $row->financial_category_id = self::refundCategory($companyId)->id;
                $row->company_id = $companyId;
                $row->user_id = $userId;
                $row->created_by_id = $userId;
                $row->amount = $ret->refund_amount;
                $row->quantity = 1;
                $row->type = 'Income';
                $row->payment_method = $ret->refund_method;
                $row->recipient = $supplierId ? (string) Supplier::withoutGlobalScopes()->find($supplierId)?->name : '';
                $row->receipt = $ret->number;
                $row->date = $ret->returned_on;
                $row->description = 'Refund for goods returned '.$ret->number;
                $row->source_type = 'purchase_return';
                $row->source_id = $ret->id;
                $row->currency = Company::withoutGlobalScopes()->find($companyId)?->currency;
                $row->save();
            }
            if ($supplierId) {
                (new SupplierService())->recalc($supplierId);
            }

            return $ret->load('items');
        });
    }

    public static function refundCategory(int $companyId): FinancialCategory
    {
        $c = FinancialCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'Supplier refunds')->first();
        if ($c === null) {
            $c = new FinancialCategory();
            $c->company_id = $companyId;
            $c->name = 'Supplier refunds';
            $c->type = 'Income';
            $c->save();
        }

        return $c;
    }
}

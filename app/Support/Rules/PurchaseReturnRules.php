<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * What a return to a supplier may contain: one rule set for the mobile API and the new web interface
 * (budget-pro-new). PurchaseReturnService::create applies the business rules.
 */
class PurchaseReturnRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId): array
    {
        return [
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'goods_receipt_id' => ['nullable', Rule::exists('goods_receipts', 'id')->where('company_id', $companyId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'refund_method' => ['nullable', 'string', 'max:30'],
            'returned_on' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}

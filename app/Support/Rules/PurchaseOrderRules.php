<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * What a purchase order, and a delivery against one, may contain: one rule set for the mobile API
 * and the new web interface (budget-pro-new). PurchaseOrderService applies the business rules.
 */
class PurchaseOrderRules
{
    /** Order statuses, in the order they happen. */
    public const STATUSES = ['draft', 'sent', 'partially_received', 'received', 'cancelled'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, bool $itemsRequired = true): array
    {
        return [
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => [$itemsRequired ? 'required' : 'sometimes', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** Receiving goods against an order (lines with no quantity are skipped by the service). @return array<string, array<int, mixed>> */
    public static function receiveRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:60'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'invoice_ref' => ['nullable', 'string', 'max:80'],
            'received_on' => ['nullable', 'date'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }
}

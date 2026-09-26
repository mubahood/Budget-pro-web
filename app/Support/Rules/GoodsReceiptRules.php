<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * What a goods receipt (receiving stock) may contain: one rule set for the mobile API and the new
 * web interface (budget-pro-new). GoodsReceiptService::receive applies the business rules.
 */
class GoodsReceiptRules
{
    /**
     * @param  bool  $costRequired  the API has always required a cost per line; the web form lets a blank
     *                              cost mean "the product's buying price" (GoodsReceiptService does that)
     * @return array<string, array<int, mixed>>
     */
    public static function rules(int $companyId, bool $costRequired = true): array
    {
        return [
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'invoice_ref' => ['nullable', 'string', 'max:80'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'received_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => [$costRequired ? 'required' : 'nullable', 'numeric', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:60'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer'],
        ];
    }
}

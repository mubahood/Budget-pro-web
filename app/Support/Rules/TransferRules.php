<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * What a stock transfer between locations may contain: one rule set for the mobile API and the new
 * web interface (budget-pro-new). TransferService::transfer applies the business rules.
 */
class TransferRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId): array
    {
        return [
            'from_location_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}

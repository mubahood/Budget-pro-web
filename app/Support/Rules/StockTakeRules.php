<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * What a stock count may contain: one rule set for the mobile API and the new web interface
 * (budget-pro-new). StockTakeService applies the business rules (the snapshot rule, E57).
 */
class StockTakeRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'stock_category_id' => ['nullable', Rule::exists('stock_categories', 'id')->where('company_id', $companyId)],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function countRules(): array
    {
        return [
            'counts' => ['required', 'array', 'min:1'],
            'counts.*.stock_item_id' => ['required', 'integer'],
            'counts.*.counted_quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}

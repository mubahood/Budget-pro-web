<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * Extra barcodes per product (a carton barcode can carry its unit), unique per company: one rule set
 * for the mobile API and the new web interface (budget-pro-new).
 */
class ProductBarcodeRules
{
    public const WRITABLE = ['stock_item_id', 'barcode', 'unit_id'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'stock_item_id' => [$partial ? 'sometimes' : 'required', Rule::exists('stock_items', 'id')->where('company_id', $companyId)],
            'barcode' => [$partial ? 'sometimes' : 'required', 'string', 'max:64',
                Rule::unique('product_barcodes', 'barcode')->where('company_id', $companyId)->where('is_deleted', 0)->ignore($ignoreId)],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('company_id', $companyId)],
        ];
    }
}

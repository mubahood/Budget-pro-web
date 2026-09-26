<?php

namespace App\Support\Rules;

use App\Models\StockItem;
use Illuminate\Validation\Rule;

/**
 * What a product (stock item) may contain: one rule set for the mobile API and the new web
 * interface (budget-pro-new), so the two can never disagree about a barcode or a price.
 */
class StockItemRules
{
    /**
     * Fields a person may set. The category comes from the sub-category (StockItem::prepare), and
     * current_quantity is derived from original_quantity on create, then moved only by StockService.
     */
    public const WRITABLE = ['stock_sub_category_id', 'name', 'description', 'image', 'barcode', 'sku', 'buying_price', 'selling_price', 'original_quantity', 'min_stock', 'allow_negative_stock', 'track_stock', 'is_active', 'unit_id', 'track_batches'];

    /** Fields shown only to people allowed to see costs (view_cost). */
    public const COST_FIELDS = ['buying_price'];

    /**
     * Fields the StockItem model refuses to change after creation (its `updating` hook), with the
     * words to explain why. A form shows them read-only instead of letting the save fail.
     */
    public const IMMUTABLE = [
        'stock_category_id' => 'The category is fixed once a product exists, so its sales history stays in the right place. To move it, duplicate the product into the other category.',
        'stock_sub_category_id' => 'The sub-category is fixed once a product exists. To move it, duplicate the product into the other sub-category.',
        'original_quantity' => 'Opening stock is recorded once, when the product is created. Change stock with a stock movement (add stock or adjust).',
    ];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'stock_sub_category_id' => [
                $partial ? 'sometimes' : 'required',
                Rule::exists('stock_sub_categories', 'id')->where('company_id', $companyId),
            ],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('stock_items', 'barcode')->where('company_id', $companyId)->where('is_deleted', 0)->ignore($ignoreId)],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('stock_items', 'sku')->where('company_id', $companyId)->where('is_deleted', 0)->ignore($ignoreId)],
            'buying_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'original_quantity' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'track_stock' => ['nullable', 'boolean'],
            'track_batches' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('company_id', $companyId)],
        ];
    }

    /**
     * Why this product should not be deleted, or null when it may be. A product with stock on the
     * shelf would take that stock's value with it; write the stock off (or deactivate) first.
     * The web interfaces apply this; the mobile API keeps its original contract (delete always tombstones).
     */
    public static function deleteBlocker(StockItem $item): ?string
    {
        if ($item->track_stock && (float) $item->current_quantity > 0) {
            $qty = rtrim(rtrim(number_format((float) $item->current_quantity, 3), '0'), '.');

            return "{$item->name} still has {$qty} in stock. Write the stock off first, or deactivate the product to hide it from selling.";
        }

        return null;
    }
}

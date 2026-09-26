<?php

namespace App\Support\Rules;

use App\Exceptions\BusinessRuleException;
use App\Models\StockSubCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What a product sub-category may contain, and when it may be deleted: one rule set for the mobile
 * API and the new web interface (budget-pro-new).
 */
class StockSubCategoryRules
{
    /** Totals (buying/selling price, current_quantity, profit, in_stock) come from update_self(). */
    public const WRITABLE = ['stock_category_id', 'name', 'description', 'status', 'image', 'measurement_unit', 'reorder_level'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'stock_category_id' => [
                $partial ? 'sometimes' : 'required',
                Rule::exists('stock_categories', 'id')->where('company_id', $companyId),
            ],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', 'max:50'],
            'image' => ['nullable', 'string', 'max:255'],
            'measurement_unit' => ['nullable', 'string', 'max:50'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** A sub-category that still holds products would leave them without a home. */
    public static function assertDeletable(StockSubCategory $sub): void
    {
        $products = DB::table('stock_items')->where('company_id', $sub->company_id)->where('stock_sub_category_id', $sub->id)->where('is_deleted', 0)->count();
        if ($products > 0) {
            throw BusinessRuleException::make('sub_category_in_use', "{$sub->name} still has {$products} product(s). Move or delete them first.", ['products' => $products]);
        }
    }
}

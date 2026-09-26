<?php

namespace App\Support\Rules;

use App\Exceptions\BusinessRuleException;
use App\Models\StockCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What a product category may contain, and when it may be deleted: one rule set for the mobile API
 * and the new web interface (budget-pro-new).
 */
class StockCategoryRules
{
    /** buying_price/selling_price/expected_profit/earned_profit are rollups the model computes. */
    public const WRITABLE = ['name', 'description', 'status', 'image'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:191', Rule::unique('stock_categories', 'name')->where('company_id', $companyId)->where('is_deleted', 0)->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', 'max:50'],
            'image' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** A category that still holds products or sub-categories would leave them without a home. */
    public static function assertDeletable(StockCategory $category): void
    {
        $products = DB::table('stock_items')->where('company_id', $category->company_id)->where('stock_category_id', $category->id)->where('is_deleted', 0)->count();
        if ($products > 0) {
            throw BusinessRuleException::make('category_in_use', "{$category->name} still has {$products} product(s). Move or delete them first.", ['products' => $products]);
        }
        $subs = DB::table('stock_sub_categories')->where('company_id', $category->company_id)->where('stock_category_id', $category->id)->where('is_deleted', 0)->count();
        if ($subs > 0) {
            throw BusinessRuleException::make('category_in_use', "{$category->name} still has {$subs} sub-categor".($subs === 1 ? 'y' : 'ies').'. Delete them first.', ['sub_categories' => $subs]);
        }
    }
}

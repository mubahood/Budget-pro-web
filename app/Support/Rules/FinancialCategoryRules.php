<?php

namespace App\Support\Rules;

use App\Models\FinancialCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Income/expense categories. A duplicate name is refused by the model itself
 * (BusinessRuleException duplicate_category), so every writer gets the same answer.
 */
class FinancialCategoryRules
{
    public const WRITABLE = ['name', 'type', 'status', 'description'];

    public const STATUSES = ['Active', 'Inactive'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:191'],
            'type' => ['nullable', Rule::in(FinancialRecordRules::TYPES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** Why this category may not be deleted, or null. Entries keep their category; hide it instead. */
    public static function deletionBlocker(FinancialCategory $category): ?string
    {
        $used = DB::table('financial_records')->where('company_id', $category->company_id)
            ->where('financial_category_id', $category->id)->where('is_deleted', 0)->count();

        return $used > 0
            ? "\"{$category->name}\" is used by {$used} ".($used === 1 ? 'entry' : 'entries').'. Set it to Inactive instead, so old entries keep their category.'
            : null;
    }
}

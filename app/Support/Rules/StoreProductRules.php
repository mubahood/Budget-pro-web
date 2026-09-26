<?php

namespace App\Support\Rules;

use App\Models\Company;
use App\Support\BarcodeResolver;
use App\Support\StoreFeatures;
use Illuminate\Validation\Rule;

/**
 * The supermarket fields of a product (SUPERMARKET_PLAN.md A2, A4, A10, A11), each only when the shop
 * has its feature on: a shop without them never sends or sees these fields.
 */
class StoreProductRules
{
    /** feature => the product fields it adds */
    public const FIELDS = [
        'weighed_items' => ['sold_by', 'plu_code'],
        'department_keys' => ['open_price'],
        'age_check' => ['min_age'],
        'deposits' => ['deposit_item_id'],
        'tax_classes' => ['tax_class_id'],
    ];

    /** @return list<string> the fields this shop's features add to the product form */
    public static function fields(?Company $company): array
    {
        $out = [];
        foreach (self::FIELDS as $feature => $fields) {
            if (StoreFeatures::enabled($company, $feature)) {
                array_push($out, ...$fields);
            }
        }

        return $out;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(?Company $company, ?int $ignoreId = null): array
    {
        $cid = (int) $company?->id;
        $all = [
            'sold_by' => ['nullable', Rule::in(BarcodeResolver::SOLD_BY)],
            'plu_code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/',
                Rule::unique('stock_items', 'plu_code')->where('company_id', $cid)->where('is_deleted', 0)->ignore($ignoreId)],
            'open_price' => ['nullable', 'boolean'],
            'min_age' => ['nullable', 'integer', 'min:1', 'max:99'],
            'deposit_item_id' => ['nullable', 'integer', Rule::exists('stock_items', 'id')->where('company_id', $cid)->where('is_deleted', 0),
                function ($attr, $value, $fail) use ($ignoreId) {
                    if ($ignoreId !== null && (int) $value === $ignoreId) {
                        $fail('A product cannot be its own deposit.');
                    }
                }],
            'tax_class_id' => ['nullable', 'integer', Rule::exists('tax_classes', 'id')->where('company_id', $cid)],
        ];

        return array_intersect_key($all, array_flip(self::fields($company)));
    }
}

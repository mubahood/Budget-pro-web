<?php

namespace App\Support\Rules;

use App\Exceptions\BusinessRuleException;
use App\Models\StockItem;
use App\Models\Unit;
use Illuminate\Validation\Rule;

/**
 * Units of measure (a pack is `factor` base units): one rule set for the mobile API and the new web
 * interface (budget-pro-new).
 */
class UnitRules
{
    public const WRITABLE = ['name', 'abbreviation', 'base_unit_id', 'factor'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:60', Rule::unique('units', 'name')->where('company_id', $companyId)->where('is_deleted', 0)->ignore($ignoreId)],
            'abbreviation' => [$partial ? 'sometimes' : 'required', 'string', 'max:15'],
            // A unit cannot be counted in itself.
            'base_unit_id' => ['nullable', Rule::exists('units', 'id')->where('company_id', $companyId), Rule::notIn(array_filter([$ignoreId]))],
            'factor' => ['nullable', 'numeric', 'min:0.001'],
        ];
    }

    /** A unit that products are still sold in cannot go. */
    public static function assertDeletable(Unit $unit): void
    {
        if (StockItem::withoutGlobalScopes()->where('unit_id', $unit->id)->exists()) {
            throw BusinessRuleException::make('unit_in_use', 'Products still use this unit.');
        }
    }
}

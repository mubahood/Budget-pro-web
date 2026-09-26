<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * What a location (a shop or store) may contain: one rule set for the mobile API and the new web
 * interface (budget-pro-new). TransferService::createLocation / updateLocation apply the plan and
 * closing rules.
 */
class LocationRules
{
    public const WRITABLE = ['name', 'address', 'is_active'];

    /**
     * A new location's duplicate name is refused by TransferService::createLocation (code
     * duplicate_location); a rename is checked here.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        $name = [$partial ? 'sometimes' : 'required', 'string', 'max:120'];
        if ($ignoreId !== null) {
            $name[] = Rule::unique('locations', 'name')->where('company_id', $companyId)->ignore($ignoreId);
        }

        return [
            'name' => $name,
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

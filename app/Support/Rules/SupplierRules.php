<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * What a supplier record may contain: one rule set for the mobile API and the new web interface
 * (budget-pro-new). `balance` is derived by SupplierService (receipts − payments − returns), never typed.
 */
class SupplierRules
{
    public const WRITABLE = ['name', 'phone', 'email', 'address', 'payment_terms_days', 'lead_time_days', 'notes', 'is_active'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:150', Rule::unique('suppliers', 'name')->where('company_id', $companyId)->where('is_deleted', 0)->ignore($ignoreId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** Paying a supplier (SupplierService::pay). */
    public static function paymentRules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}

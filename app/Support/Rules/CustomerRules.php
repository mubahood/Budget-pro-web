<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * What a customer record may contain: one rule set for the mobile API and the new web interface
 * (budget-pro-new), so the two can never disagree about a phone number or a credit limit.
 */
class CustomerRules
{
    /** Fields a person may edit (balance is derived by CustomerService, never typed). */
    public const WRITABLE = ['name', 'phone', 'email', 'address', 'credit_limit', 'payment_terms_days', 'reminders_enabled', 'notes', 'is_active'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'phone')->where('company_id', $companyId)->where('is_deleted', 0)->ignore($ignoreId)],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'reminders_enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

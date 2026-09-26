<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * An income or expense entry typed by a person (financial_records). System-posted rows
 * (source_type set: sales, payments, deliveries, supplier payments) are never written through
 * these rules — the model refuses to change them (ledger_locked).
 */
class FinancialRecordRules
{
    public const WRITABLE = ['financial_category_id', 'amount', 'quantity', 'type', 'payment_method', 'recipient', 'description', 'receipt', 'date'];

    public const TYPES = ['Income', 'Expense'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'financial_category_id' => [$partial ? 'sometimes' : 'required', Rule::exists('financial_categories', 'id')->where('company_id', $companyId)],
            'amount' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'type' => [$partial ? 'sometimes' : 'required', Rule::in(self::TYPES)],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'recipient' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
        ];
    }
}

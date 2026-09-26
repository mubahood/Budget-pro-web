<?php

namespace App\Support\Rules;

use App\Models\FinancialPeriod;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Financial periods (the shop's accounting years/terms). The model keeps one Active period per
 * shop, stamps closed_at/closed_by_id when a period is Closed, and FinancialPeriod::resolveFor()
 * refuses any new entry dated inside a Closed period.
 */
class FinancialPeriodRules
{
    public const WRITABLE = ['name', 'start_date', 'end_date', 'status', 'description'];

    public const STATUSES = ['Active', 'Closed', 'Inactive'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:191'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Why this period may not be closed now, or null. Closing is for a finished period: once closed,
     * nothing dated inside it can be added, changed or deleted (FinancialPeriod::resolveFor and the
     * FinancialRecord hooks refuse). So the Active period (the fallback for every new sale and
     * expense) and a period that has not ended yet are refused, or the till would stop working.
     */
    public static function closingBlocker(FinancialPeriod $period, ?Carbon $today = null): ?string
    {
        if ($period->status === 'Closed') {
            return "{$period->name} is already closed.";
        }
        if ($period->status === 'Active') {
            return "{$period->name} is the active period, where new sales and expenses go. Make the next period active first, then close this one.";
        }
        $today ??= LocalDate::today((int) $period->company_id);
        if ($period->end_date !== null && $period->end_date->toDateString() >= $today->toDateString()) {
            return "{$period->name} runs until ".$period->end_date->format('d M Y').'. Close it after it ends, or sales and expenses dated inside it will be refused.';
        }

        return null;
    }
}

<?php

namespace App\Support;

use App\Models\Company;

/** Tenant currency helpers — no currency code is ever hardcoded in views/controllers. */
class Money
{
    /** @var array<int, string> */
    private static array $cache = [];

    public static function symbol(?int $companyId = null): string
    {
        $companyId = $companyId ?? (int) (auth()->user()?->company_id ?? auth('admin')->user()?->company_id ?? 0);
        if ($companyId <= 0) {
            return (string) config('saas.default_currency');
        }
        if (! isset(self::$cache[$companyId])) {
            $code = Company::withoutGlobalScopes()->find($companyId)?->currency;
            self::$cache[$companyId] = $code ? strtoupper((string) $code) : (string) config('saas.default_currency');
        }

        return self::$cache[$companyId];
    }

    public static function format(float|int|string|null $amount, int $decimals = 0, ?int $companyId = null): string
    {
        return self::symbol($companyId).' '.number_format((float) $amount, $decimals);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}

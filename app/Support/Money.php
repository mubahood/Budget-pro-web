<?php

namespace App\Support;

use App\Models\Company;

/** Tenant currency helpers — no currency code is ever hardcoded in views/controllers. */
class Money
{
    /** @var array<int, string> */
    private static array $cache = [];

    /** Currencies counted in whole units at the till (no cents on a shelf price). Everything else shows two decimals. */
    public const ZERO_DECIMAL = ['UGX', 'RWF', 'TZS', 'KES', 'BIF', 'CDF', 'SSP', 'SOS', 'MWK', 'NGN', 'XOF', 'XAF', 'GNF', 'MGA', 'KMF', 'DJF', 'SLE', 'SLL',
        'JPY', 'KRW', 'VND', 'IDR', 'CLP', 'PYG', 'COP', 'ISK', 'HUF', 'UZS', 'LAK', 'MMK', 'IQD', 'IRR', 'LBP'];

    /** How many decimals this shop's money is shown with: 0 for shillings and the like, 2 for dollars, euros, rand… */
    public static function decimals(?int $companyId = null): int
    {
        return in_array(self::symbol($companyId), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

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

    public static function format(float|int|string|null $amount, ?int $decimals = null, ?int $companyId = null): string
    {
        return self::symbol($companyId).' '.number_format((float) $amount, $decimals ?? self::decimals($companyId));
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}

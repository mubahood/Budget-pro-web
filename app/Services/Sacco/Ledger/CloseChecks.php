<?php

namespace App\Services\Sacco\Ledger;

use App\Models\SaccoPeriod;

/**
 * What else must be true before a month closes (ADR 0009 §8), beside the ledger check PeriodService always runs:
 * each module that can leave a month unfinished registers one check (the approvals engine: no request still
 * pending from that month; loans in P2: that month's accrual, penalty and provisioning runs are done). A check
 * throws a BusinessRuleException to stop the close.
 */
final class CloseChecks
{
    /** @var array<string, callable(int, SaccoPeriod): void> */
    private static array $checks = [];

    /** @param  callable(int, SaccoPeriod): void  $check */
    public static function register(string $key, callable $check): void
    {
        self::$checks[$key] = $check;
    }

    /** @return array<string, callable(int, SaccoPeriod): void> */
    public static function all(): array
    {
        return self::$checks;
    }
}

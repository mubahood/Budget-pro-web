<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;

/**
 * The registry of member sub-ledgers (ADR 0009 §7). Each module that owns a sub-ledger table registers its
 * SubLedger once at boot (App\Providers\AppServiceProvider); a line naming a type nobody registered is refused
 * before anything is written, and the verifier reports any such line already in the books.
 */
final class SubLedgers
{
    /** @var array<string, SubLedger> */
    private static array $ledgers = [];

    public static function register(SubLedger $ledger): void
    {
        self::$ledgers[$ledger->type()] = $ledger;
    }

    public static function forget(string $type): void
    {
        unset(self::$ledgers[$type]);
    }

    public static function has(string $type): bool
    {
        return isset(self::$ledgers[$type]);
    }

    public static function for(string $type): SubLedger
    {
        return self::$ledgers[$type] ?? throw BusinessRuleException::make('sub_ledger_unavailable', "The books cannot post to {$type} accounts yet.");
    }

    /** @return array<string, SubLedger> */
    public static function all(): array
    {
        return self::$ledgers;
    }
}

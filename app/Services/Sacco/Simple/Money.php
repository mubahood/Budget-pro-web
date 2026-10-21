<?php

namespace App\Services\Sacco\Simple;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoAccountBalance;
use App\Models\SaccoLedgerAccount;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Loans\LoanBooks;

/**
 * The SACCO's money, in the words of the simple screens: cash, bank and mobile money, and their total ("the SACCO
 * balance"). Read from the ledger's balance caches, which only PostingService writes.
 */
final class Money
{
    public const METHODS = ['cash', 'bank', 'mobile_money'];

    public const LABELS = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_money' => 'Mobile money'];

    /** The system keys of each method's accounts (the first is where new money goes). */
    private const KEYS = ['cash' => ['cash.office', 'cash.box', 'cash.petty'], 'bank' => ['bank.main'], 'mobile_money' => ['momo.float', 'momo.clearing']];

    /** The ledger account money moves through for a method. */
    public static function account(int $companyId, string $method): int
    {
        if (! in_array($method, self::METHODS, true)) {
            throw BusinessRuleException::make('invalid_method', 'Choose cash, bank or mobile money.');
        }
        $mode = LoanBooks::settings($companyId)->mode;

        return match ($method) {
            'cash' => ChartOfAccounts::id($companyId, $mode === 'group' ? 'cash.box' : 'cash.office'),
            'bank' => ChartOfAccounts::id($companyId, 'bank.main'),
            default => ChartOfAccounts::id($companyId, 'momo.float'),
        };
    }

    /** @return array<string, list<int>> method => the SACCO's account ids */
    public static function accountIds(int $companyId): array
    {
        $byKey = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('system_key')->pluck('id', 'system_key')->all();
        $out = [];
        foreach (self::KEYS as $method => $keys) {
            $out[$method] = array_values(array_map('intval', array_filter(array_map(fn ($k) => $byKey[$k] ?? null, $keys))));
        }

        return $out;
    }

    /** @return list<int> every money account */
    public static function allIds(int $companyId): array
    {
        return array_merge(...array_values(self::accountIds($companyId)));
    }

    /** @return array{cash: int, bank: int, mobile_money: int, total: int} */
    public static function balances(int $companyId): array
    {
        $bal = SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $companyId)->pluck('balance_minor', 'ledger_account_id')->all();
        $out = ['cash' => 0, 'bank' => 0, 'mobile_money' => 0, 'total' => 0];
        foreach (self::accountIds($companyId) as $method => $ids) {
            foreach ($ids as $id) {
                $out[$method] += (int) ($bal[$id] ?? 0);
            }
            $out['total'] += $out[$method];
        }

        return $out;
    }

    /** What is in the account a method pays from (only the first account: the one money goes out of). */
    public static function available(int $companyId, string $method): int
    {
        return (int) (SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $companyId)->where('ledger_account_id', self::account($companyId, $method))->value('balance_minor') ?? 0);
    }
}

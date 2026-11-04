<?php

namespace App\Services\Rentals\Payments;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Support\Rentals\Minor;

/**
 * Money going out must be in the account it goes out of (payouts, expenses, refunds, transfers): the cash book stays
 * real. The refusal says how much is recorded there and how to fix it (record the banking of cash first).
 */
final class CashGuard
{
    public const LABELS = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_money' => 'Mobile money', 'cheque' => 'Cheques'];

    public static function assertCovers(int $companyId, string $method, int $amount): void
    {
        $have = RentalLedger::cash($companyId)[$method] ?? 0;
        if ($amount > $have) {
            $label = self::LABELS[$method] ?? $method;
            throw BusinessRuleException::make('insufficient_cash', "Only ".Minor::format(max(0, $have), $companyId)." is recorded in {$label}. "
                .'If the money is in another account, record moving it first (Cash book › Move money). If it was there before these books began, enter that account\'s opening balance.', ['method' => $method, 'available_minor' => $have]);
        }
    }
}

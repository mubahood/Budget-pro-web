<?php

namespace App\Services\Sacco\Channels;

use App\Services\Sacco\Payouts\PayoutService;
use App\Services\Sacco\SaccoTransactions;

/**
 * Wires the P3 parts (money channels, check-off, tills, member self-service) into the rest at boot, from
 * SaccoModule::boot, so no earlier part needs to know the new tables.
 *
 * - P3.3: a withdrawal paid by mobile money hears its payout's final answer (the receipt, or its reversal).
 */
final class ChannelsModule
{
    public static function boot(): void
    {
        PayoutService::onResult('withdrawal', [SaccoTransactions::class, 'payoutResult']);
    }
}

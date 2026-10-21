<?php

namespace App\Services\Sacco\Payouts;

final class PayoutRequest
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $phoneE164,
        public readonly ?string $network,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $narration,
    ) {
    }
}

<?php

namespace App\Services\Sacco\Payouts;

/** What a provider said: sent (accepted, not final), successful, failed or unknown (ask again later). */
final class PayoutResult
{
    public const STATUSES = ['sent', 'successful', 'failed', 'unknown'];

    public function __construct(public readonly string $status, public readonly ?string $providerRef = null, public readonly ?string $error = null)
    {
    }
}

<?php

namespace App\Services\Sacco\Payouts;

final class AccountCheck
{
    public function __construct(public readonly bool $valid, public readonly ?string $name = null, public readonly ?string $network = null)
    {
    }
}

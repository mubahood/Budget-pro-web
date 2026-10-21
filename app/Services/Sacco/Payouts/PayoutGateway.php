<?php

namespace App\Services\Sacco\Payouts;

/** A mobile-money payout provider (04 §11.2, AK-17). Every send is idempotent on the request's key. */
interface PayoutGateway
{
    /** 'simulated' | 'flutterwave' */
    public function key(): string;

    public function validateAccount(string $e164, ?string $network): AccountCheck;

    public function send(PayoutRequest $r): PayoutResult;

    public function status(string $providerRef): PayoutResult;
}

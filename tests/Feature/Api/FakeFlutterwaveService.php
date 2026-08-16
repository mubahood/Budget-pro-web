<?php

namespace Tests\Feature\Api;

use App\Services\FlutterwaveService;

/**
 * Test double for FlutterwaveService — no network. Records the payload sent to
 * initiatePayment() and returns a configurable verification result. The pure
 * logic (signature check, transactionSatisfies) is inherited unchanged.
 */
class FakeFlutterwaveService extends FlutterwaveService
{
    public array $lastPayload = [];
    public bool $initiateSuccess = true;
    public array $verifyResult = ['success' => false];

    public function __construct()
    {
        // Intentionally do not build an HTTP client.
    }

    public function initiatePayment(array $payload): array
    {
        $this->lastPayload = $payload;

        if (! $this->initiateSuccess) {
            return ['success' => false, 'message' => 'gateway down'];
        }

        return ['success' => true, 'link' => 'https://checkout.flutterwave.test/pay/'.($payload['tx_ref'] ?? 'x')];
    }

    public function verifyTransaction(int|string $transactionId): array
    {
        return $this->verifyResult;
    }

    /**
     * Convenience: configure a successful verification for a given reference.
     */
    public function willVerify(string $txRef, float $amount, string $currency, int $id = 999001): void
    {
        $this->verifyResult = [
            'success' => true,
            'data' => [
                'id' => $id,
                'tx_ref' => $txRef,
                'status' => 'successful',
                'amount' => $amount,
                'currency' => $currency,
                'payment_type' => 'mobilemoneyuganda',
                'flw_ref' => 'FLW-TEST-'.$id,
            ],
        ];
    }
}

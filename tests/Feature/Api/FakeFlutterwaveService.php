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

    /** @var array<int, array{type: string, payload: array}> */
    public array $charges = [];

    public array $subaccounts = [];

    /** tx_ref => verification data (status successful|failed|pending) */
    public array $byReference = [];

    public function createSubaccount(array $payload): array
    {
        $this->subaccounts[] = $payload;

        return ['success' => true, 'id' => 'RS_'.strtoupper(substr(md5(json_encode($payload)), 0, 10))];
    }

    public function chargeMobileMoney(string $type, array $payload): array
    {
        $this->charges[] = ['type' => $type, 'payload' => $payload];

        return ['success' => true, 'status' => 'pending', 'id' => (string) (700000 + count($this->charges)), 'redirect' => null];
    }

    public function verifyByReference(string $txRef): array
    {
        return isset($this->byReference[$txRef]) ? ['success' => true, 'data' => $this->byReference[$txRef]] : ['success' => false, 'message' => 'pending'];
    }

    /** The customer approved (or declined) the prompt on their phone. */
    public function customerAnswers(string $txRef, float $amount, string $currency, string $status = 'successful'): void
    {
        $this->byReference[$txRef] = ['id' => 880000 + count($this->byReference), 'tx_ref' => $txRef, 'status' => $status, 'amount' => $amount, 'currency' => $currency, 'payment_type' => 'mobilemoneyuganda'];
    }
}

<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Thin, defensive wrapper around the Flutterwave v3 REST API.
 *
 * Only the calls we need: initiate a hosted payment, verify a transaction, and
 * validate an incoming webhook signature. All network failures are caught and
 * surfaced as structured results (never uncaught exceptions), so callers can
 * fail safely without leaving half-completed billing state.
 */
class FlutterwaveService
{
    private ?Client $client = null;

    /**
     * Lazily build the HTTP client from config. Built lazily (not via constructor
     * injection) so the Laravel container never autowires a mis-configured Client,
     * and so test doubles can override the network methods without any client.
     */
    protected function client(): Client
    {
        // Tests must never reach Flutterwave (a developer's .env may hold a live key): bind a fake instead.
        if (app()->runningUnitTests() && ! config('flutterwave.allow_network_in_tests', false)) {
            throw new \LogicException('FlutterwaveService made a network call during tests. Bind a fake (FakeFlutterwaveService / SimulatedFlutterwave).');
        }
        if ($this->client === null) {
            $this->client = new Client([
                'base_uri' => config('flutterwave.base_url').'/',
                'timeout' => config('flutterwave.timeout', 20),
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer '.config('flutterwave.secret_key'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);
        }

        return $this->client;
    }

    /**
     * Initiate a hosted (Standard) payment. Returns a link the client opens.
     *
     * @return array{success: bool, link?: string, message?: string, raw?: array}
     */
    public function initiatePayment(array $payload): array
    {
        try {
            $response = $this->client()->post('v3/payments', ['json' => $payload]);
            $body = json_decode((string) $response->getBody(), true) ?: [];

            if (($body['status'] ?? null) === 'success' && ! empty($body['data']['link'])) {
                return ['success' => true, 'link' => $body['data']['link'], 'raw' => $body];
            }

            Log::warning('Flutterwave initiatePayment failed', ['body' => $body]);

            return ['success' => false, 'message' => $body['message'] ?? 'Unable to initiate payment.', 'raw' => $body];
        } catch (GuzzleException $e) {
            Log::error('Flutterwave initiatePayment error', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Payment gateway is unreachable. Please try again.'];
        }
    }

    /**
     * Verify a transaction by Flutterwave transaction id.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    /**
     * Collections subaccount for a shop (plan Part E3): the shop's mobile-money number receives its sales.
     *
     * @return array{success: bool, id?: string, message?: string}
     */
    public function createSubaccount(array $payload): array
    {
        try {
            $body = json_decode((string) $this->client()->post('v3/subaccounts', ['json' => $payload])->getBody(), true) ?: [];
            if (($body['status'] ?? null) === 'success' && ! empty($body['data']['subaccount_id'])) {
                return ['success' => true, 'id' => (string) $body['data']['subaccount_id']];
            }

            return ['success' => false, 'message' => $body['message'] ?? 'Could not register the mobile money number.'];
        } catch (GuzzleException $e) {
            return ['success' => false, 'message' => 'Payment provider unreachable.'];
        }
    }

    /**
     * Mobile-money charge: the customer approves on their phone (plan Part E3).
     *
     * @return array{success: bool, status?: string, id?: string, redirect?: string, message?: string}
     */
    public function chargeMobileMoney(string $type, array $payload): array
    {
        try {
            $body = json_decode((string) $this->client()->post('v3/charges?type='.urlencode($type), ['json' => $payload])->getBody(), true) ?: [];
            if (($body['status'] ?? null) === 'success') {
                return ['success' => true, 'status' => (string) ($body['data']['status'] ?? 'pending'), 'id' => isset($body['data']['id']) ? (string) $body['data']['id'] : null,
                    'redirect' => $body['meta']['authorization']['redirect'] ?? null];
            }

            return ['success' => false, 'message' => $body['message'] ?? 'The mobile money request failed.'];
        } catch (GuzzleException $e) {
            return ['success' => false, 'message' => 'Payment provider unreachable.'];
        }
    }

    /** @return array{success: bool, data?: array, message?: string} */
    /**
     * `transient` is true when the provider could not answer (network error or 5xx): the caller should
     * retry later rather than treat the payment as failed.
     *
     * @return array{success: bool, data?: array, message?: string, transient?: bool}
     */
    public function verifyByReference(string $txRef): array
    {
        try {
            $response = $this->client()->get('v3/transactions/verify_by_reference', ['query' => ['tx_ref' => $txRef]]);
            $body = json_decode((string) $response->getBody(), true) ?: [];
            if (($body['status'] ?? null) === 'success' && isset($body['data'])) {
                return ['success' => true, 'data' => $body['data']];
            }

            return ['success' => false, 'message' => $body['message'] ?? 'Not found.', 'transient' => $response->getStatusCode() >= 500];
        } catch (GuzzleException $e) {
            return ['success' => false, 'message' => 'Payment provider unreachable.', 'transient' => true];
        }
    }

    /**
     * Charge a saved card (auto-renew). The token comes from an earlier card payment's verification.
     *
     * @return array{success: bool, data?: array, message?: string, transient?: bool}
     */
    public function chargeToken(array $payload): array
    {
        try {
            $response = $this->client()->post('v3/tokenized-charges', ['json' => $payload]);
            $body = json_decode((string) $response->getBody(), true) ?: [];
            if (($body['status'] ?? null) === 'success' && isset($body['data'])) {
                return ['success' => true, 'data' => $body['data']];
            }

            return ['success' => false, 'message' => $body['message'] ?? 'The card could not be charged.', 'transient' => $response->getStatusCode() >= 500];
        } catch (GuzzleException $e) {
            return ['success' => false, 'message' => 'Payment provider unreachable.', 'transient' => true];
        }
    }

    /**
     * Refund a verified transaction, fully or in part.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function refund(int|string $transactionId, ?float $amount = null): array
    {
        try {
            $body = json_decode((string) $this->client()->post("v3/transactions/{$transactionId}/refund", ['json' => array_filter(['amount' => $amount])])->getBody(), true) ?: [];
            if (($body['status'] ?? null) === 'success') {
                return ['success' => true, 'data' => $body['data'] ?? []];
            }

            return ['success' => false, 'message' => $body['message'] ?? 'The refund was refused.'];
        } catch (GuzzleException $e) {
            return ['success' => false, 'message' => 'Payment provider unreachable.'];
        }
    }

    public function verifyTransaction(int|string $transactionId): array
    {
        try {
            $response = $this->client()->get("v3/transactions/{$transactionId}/verify");
            $body = json_decode((string) $response->getBody(), true) ?: [];

            if (($body['status'] ?? null) === 'success' && ! empty($body['data'])) {
                return ['success' => true, 'data' => $body['data']];
            }

            return ['success' => false, 'message' => $body['message'] ?? 'Verification failed.', 'transient' => $response->getStatusCode() >= 500];
        } catch (GuzzleException $e) {
            Log::error('Flutterwave verifyTransaction error', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Payment gateway is unreachable. Please try again.', 'transient' => true];
        }
    }

    /**
     * Verify the "verif-hash" signature header on an incoming webhook, using a
     * constant-time comparison against the configured secret hash.
     */
    public function verifyWebhookSignature(?string $signature): bool
    {
        $secret = (string) config('flutterwave.secret_hash');

        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals($secret, $signature);
    }

    /**
     * Decide whether a verified transaction actually paid for what we expected:
     * status successful, and amount + currency at least what was billed.
     */
    public function transactionSatisfies(array $data, float $expectedAmount, string $expectedCurrency): bool
    {
        $status = strtolower((string) ($data['status'] ?? ''));
        $amount = (float) ($data['amount'] ?? ($data['charged_amount'] ?? 0));
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        return $status === 'successful'
            && $currency === strtoupper($expectedCurrency)
            && $amount + 0.001 >= $expectedAmount; // allow float epsilon
    }
}

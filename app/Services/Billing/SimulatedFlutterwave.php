<?php

namespace App\Services\Billing;

use App\Services\FlutterwaveService;
use Illuminate\Support\Facades\Cache;

/**
 * A stand-in for Flutterwave that never touches the network, for local browser checks and tests
 * (POWER_PLAN §4: nothing may reach a live key outside production). Bound only when the app runs
 * locally with `billing.fake_payments` on, or by a test.
 *
 * Behaviour, remembered in the cache so it survives between requests:
 *  - hosted checkout returns a link straight to the redirect URL with status=successful;
 *  - a mobile-money prompt is "approved" after $approveAfter seconds — or declined when the phone
 *    number ends in 000, so the failure path can be seen too;
 *  - verification answers with exactly what was charged; a saved card charges at once.
 */
class SimulatedFlutterwave extends FlutterwaveService
{
    public function __construct(private readonly int $approveAfter = 6)
    {
    }

    private function key(string $txRef): string
    {
        return 'sim-flw:'.$txRef;
    }

    private function remember(string $txRef, array $row): void
    {
        Cache::put($this->key($txRef), $row, now()->addDay());
    }

    public function initiatePayment(array $payload): array
    {
        $txRef = (string) ($payload['tx_ref'] ?? '');
        $id = 'SIM'.random_int(100000, 999999);
        $this->remember($txRef, ['id' => $id, 'amount' => $payload['amount'] ?? 0, 'currency' => $payload['currency'] ?? 'UGX', 'at' => 0, 'type' => 'card', 'fail' => false]);
        Cache::put('sim-flw-id:'.$id, $txRef, now()->addDay());
        $url = (string) ($payload['redirect_url'] ?? '');

        return ['success' => true, 'link' => $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['status' => 'successful', 'tx_ref' => $txRef, 'transaction_id' => $id])];
    }

    public function chargeMobileMoney(string $type, array $payload): array
    {
        $txRef = (string) ($payload['tx_ref'] ?? '');
        $id = 'SIM'.random_int(100000, 999999);
        $this->remember($txRef, ['id' => $id, 'amount' => $payload['amount'] ?? 0, 'currency' => $payload['currency'] ?? 'UGX', 'at' => time(), 'type' => $type,
            'fail' => str_ends_with((string) ($payload['phone_number'] ?? ''), '000')]);
        Cache::put('sim-flw-id:'.$id, $txRef, now()->addDay());

        return ['success' => true, 'status' => 'pending', 'id' => $id];
    }

    public function verifyByReference(string $txRef): array
    {
        $row = Cache::get($this->key($txRef));
        if ($row === null) {
            return ['success' => false, 'message' => 'No transaction was found for this reference.'];
        }
        $status = $row['fail'] ? 'failed' : ((time() - (int) $row['at']) >= $this->approveAfter ? 'successful' : 'pending');

        return ['success' => true, 'data' => $this->data($txRef, $row, $status)];
    }

    public function verifyTransaction(int|string $transactionId): array
    {
        $txRef = Cache::get('sim-flw-id:'.$transactionId);
        $row = $txRef ? Cache::get($this->key($txRef)) : null;

        return $row ? ['success' => true, 'data' => $this->data($txRef, $row, $row['fail'] ? 'failed' : 'successful')] : ['success' => false, 'message' => 'Transaction not found.'];
    }

    public function chargeToken(array $payload): array
    {
        $txRef = (string) ($payload['tx_ref'] ?? '');
        $id = 'SIM'.random_int(100000, 999999);
        $row = ['id' => $id, 'amount' => $payload['amount'] ?? 0, 'currency' => $payload['currency'] ?? 'UGX', 'at' => 0, 'type' => 'card', 'fail' => false];
        $this->remember($txRef, $row);
        Cache::put('sim-flw-id:'.$id, $txRef, now()->addDay());

        return ['success' => true, 'data' => $this->data($txRef, $row, 'successful')];
    }

    public function refund(int|string $transactionId, ?float $amount = null): array
    {
        return ['success' => true, 'data' => ['id' => 'SIMR'.random_int(1000, 9999), 'amount_refunded' => $amount]];
    }

    public function createSubaccount(array $payload): array
    {
        return ['success' => true, 'id' => 'RS_SIM'.strtoupper(substr(md5(json_encode($payload)), 0, 8))];
    }

    private function data(string $txRef, array $row, string $status): array
    {
        $card = $row['type'] === 'card';

        return ['id' => $row['id'], 'tx_ref' => $txRef, 'status' => $status, 'amount' => (float) $row['amount'], 'currency' => $row['currency'],
            'payment_type' => $card ? 'card' : 'mobilemoneyug', 'flw_ref' => 'SIM-'.$row['id'],
            'card' => $card ? ['token' => 'flw-t1nf-sim-'.substr(md5($txRef), 0, 12), 'last_4digits' => '4242', 'type' => 'VISA', 'expiry' => '12/30'] : null];
    }
}

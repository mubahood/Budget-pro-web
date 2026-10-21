<?php

namespace App\Services\Sacco\Payouts;

use App\Support\Sacco\Amount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Mobile-money payouts through Flutterwave's Transfers API (04 §11.2, P3.2, AK-101). Not used until the owner adds keys
 * and sets SACCO_PAYOUTS_DRIVER=flutterwave; in tests it only ever talks to Http::fake (stray requests are refused).
 *
 * - send() posts one transfer whose `reference` is the payout's idempotency key, so a retry (the same key) can never
 *   pay twice: Flutterwave refuses a second transfer with a reference it has seen, and that refusal is read as
 *   "already sent, ask its status" rather than a failure.
 * - A network error or a 5xx is `unknown` (sacco:payouts-poll asks again); a 4xx refusal is `failed` (the sent
 *   journal is reversed); NEW and PENDING are `sent`; SUCCESSFUL and FAILED are final.
 * - Amounts leave as exact decimal strings in major units, never floats.
 */
final class FlutterwavePayouts implements PayoutGateway
{
    /** Flutterwave's account_bank for mobile-money wallets, per currency. */
    public const MOMO_BANK = ['UGX' => 'MPS', 'KES' => 'MPS', 'RWF' => 'MPS', 'TZS' => 'MPS'];

    public function key(): string
    {
        return 'flutterwave';
    }

    public static function configured(): bool
    {
        return filled(config('flutterwave.secret_key'));
    }

    public function validateAccount(string $e164, ?string $network): AccountCheck
    {
        $ok = preg_match('/^\+\d{9,15}$/', $e164) === 1;
        $net = $network ?? (str_starts_with($e164, '+25670') || str_starts_with($e164, '+25675') || str_starts_with($e164, '+25674') ? 'airtel' : (str_starts_with($e164, '+256') ? 'mtn' : null));

        return new AccountCheck($ok, null, $net);
    }

    public function send(PayoutRequest $r): PayoutResult
    {
        $body = ['account_bank' => self::MOMO_BANK[$r->currency] ?? 'MPS', 'account_number' => ltrim($r->phoneE164, '+'), 'amount' => Amount::of($r->amountMinor, $r->currency)->toMajorString(),
            'currency' => $r->currency, 'debit_currency' => $r->currency, 'reference' => $r->idempotencyKey, 'narration' => mb_substr($r->narration, 0, 90),
            'beneficiary_name' => 'Member', 'meta' => [['mobile_number' => ltrim($r->phoneE164, '+')]]];
        try {
            $res = $this->http()->post('v3/transfers', $body);
        } catch (ConnectionException $e) {
            return new PayoutResult('unknown', 'ref:'.$r->idempotencyKey, 'The provider did not answer: asked again later.');
        }
        if ($res->serverError()) {
            return new PayoutResult('unknown', 'ref:'.$r->idempotencyKey, 'The provider had an error: asked again later.');
        }
        $message = (string) $res->json('message', '');
        if ($res->clientError() || $res->json('status') !== 'success') {
            if (stripos($message, 'reference') !== false && (stripos($message, 'exist') !== false || stripos($message, 'duplicate') !== false)) {
                return $this->status('ref:'.$r->idempotencyKey); // already sent with this key: its status, never a second transfer
            }

            return new PayoutResult('failed', null, mb_substr($message !== '' ? $message : 'The provider refused the transfer.', 0, 250));
        }

        return $this->map($res, (string) $res->json('data.id'));
    }

    /** The transfer's status, by its id or (before the id is known) by the reference it was sent with ("ref:{key}"). */
    public function status(string $providerRef): PayoutResult
    {
        try {
            if (str_starts_with($providerRef, 'ref:')) {
                $res = $this->http()->get('v3/transfers', ['reference' => substr($providerRef, 4)]);
                if ($res->successful() && is_array($res->json('data.0'))) {
                    $id = (string) $res->json('data.0.id');

                    return $this->result((string) $res->json('data.0.status'), $id, (string) $res->json('data.0.complete_message', ''));
                }

                return new PayoutResult('unknown', $providerRef, 'The provider does not know the transfer yet.');
            }
            $res = $this->http()->get('v3/transfers/'.rawurlencode($providerRef));
        } catch (ConnectionException) {
            return new PayoutResult('unknown', $providerRef, 'The provider did not answer: asked again later.');
        }
        if (! $res->successful()) {
            return new PayoutResult('unknown', $providerRef, 'The provider could not say: asked again later.');
        }

        return $this->map($res, $providerRef);
    }

    private function map(Response $res, string $id): PayoutResult
    {
        return $this->result((string) $res->json('data.status'), $id !== '' ? $id : null, (string) $res->json('data.complete_message', ''));
    }

    private function result(string $status, ?string $id, string $message): PayoutResult
    {
        return match (strtoupper($status)) {
            'SUCCESSFUL' => new PayoutResult('successful', $id),
            'FAILED' => new PayoutResult('failed', $id, mb_substr($message !== '' ? $message : 'The transfer failed.', 0, 250)),
            'NEW', 'PENDING' => new PayoutResult('sent', $id),
            default => new PayoutResult('unknown', $id, 'The provider answered "'.mb_substr($status, 0, 20).'".'),
        };
    }

    private function http(): PendingRequest
    {
        if (app()->runningUnitTests() && ! config('flutterwave.allow_network_in_tests', false)) {
            Http::preventStrayRequests(); // tests reach only Http::fake, never Flutterwave
        }

        return Http::baseUrl((string) config('flutterwave.base_url').'/')->withToken((string) config('flutterwave.secret_key'))->acceptJson()->asJson()
            ->timeout((int) config('flutterwave.timeout', 20));
    }
}

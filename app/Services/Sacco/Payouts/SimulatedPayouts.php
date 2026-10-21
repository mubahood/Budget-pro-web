<?php

namespace App\Services\Sacco\Payouts;

use App\Models\SaccoPayout;

/**
 * The payout provider for local work and tests (AK-17): no network call ever. A phone ending in 000 fails, one ending
 * in 999 stays unknown (to exercise the poll), any other succeeds once `sacco.payouts.simulated_delay_seconds` have
 * passed since it was sent (0: at once). config:doctor fails production with this driver.
 */
final class SimulatedPayouts implements PayoutGateway
{
    public function key(): string
    {
        return 'simulated';
    }

    public function validateAccount(string $e164, ?string $network): AccountCheck
    {
        return new AccountCheck(preg_match('/^\+\d{9,15}$/', $e164) === 1, 'Simulated wallet', $network ?? (str_starts_with($e164, '+25670') || str_starts_with($e164, '+25675') ? 'airtel' : 'mtn'));
    }

    public function send(PayoutRequest $r): PayoutResult
    {
        $ref = 'SIM-'.substr(hash('sha256', $r->idempotencyKey), 0, 16);
        if (str_ends_with($r->phoneE164, '000')) {
            return new PayoutResult('failed', $ref, 'The simulated wallet refused the transfer.');
        }
        if (str_ends_with($r->phoneE164, '999')) {
            return new PayoutResult('unknown', $ref);
        }

        return $this->delay() === 0 ? new PayoutResult('successful', $ref) : new PayoutResult('sent', $ref);
    }

    public function status(string $providerRef): PayoutResult
    {
        $p = SaccoPayout::withoutGlobalScopes()->where('provider_ref', $providerRef)->first();
        if ($p === null) {
            return new PayoutResult('failed', $providerRef, 'No such transfer.');
        }
        if (str_ends_with((string) $p->phone_e164, '999')) {
            return new PayoutResult('unknown', $providerRef);
        }
        if (str_ends_with((string) $p->phone_e164, '000')) {
            return new PayoutResult('failed', $providerRef, 'The simulated wallet refused the transfer.');
        }

        return $p->sent_at !== null && $p->sent_at->copy()->addSeconds($this->delay())->lte(now()) ? new PayoutResult('successful', $providerRef) : new PayoutResult('sent', $providerRef);
    }

    private function delay(): int
    {
        return (int) config('sacco.payouts.simulated_delay_seconds', 0);
    }
}

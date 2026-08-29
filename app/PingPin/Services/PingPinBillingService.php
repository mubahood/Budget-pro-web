<?php

namespace App\PingPin\Services;

use App\Models\Company;
use App\Models\PingPinPlan;
use App\Models\PingPinSubscriptionInvoice;
use App\Services\FlutterwaveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors BillingController's checkout -> verify(client)/webhook(server) ->
 * fulfill() flow exactly (PLAN.md §7) — that pattern is proven and already
 * live-verified against the real Flutterwave API for budget-pro's own
 * billing. FlutterwaveService itself is reused UNMODIFIED (it's a stateless
 * payment-provider wrapper with no knowledge of which product's tables it's
 * being used for); only the invoice/subscription side is Ping Pin's own.
 */
class PingPinBillingService
{
    public function __construct(private FlutterwaveService $flutterwave)
    {
    }

    /**
     * @return array{success: bool, message?: string, payment_link?: string, tx_ref?: string, amount?: float, currency?: string, invoice_id?: int}
     */
    public function checkout(Company $company, PingPinPlan $plan, \App\Models\User $user): array
    {
        $isUganda = $company->isUgandaBilling();
        ['amount' => $amount, 'currency' => $currency] = $plan->chargeFor($isUganda);

        if ($amount <= 0) {
            return ['success' => false, 'message' => 'This plan is not available for purchase.'];
        }

        $invoice = PingPinSubscriptionInvoice::create([
            'company_id' => $company->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'provider' => 'flutterwave',
            'meta' => ['plan_id' => $plan->id, 'is_uganda' => $isUganda],
        ]);

        $txRef = 'PPIN-'.$invoice->id.'-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10));
        $invoice->provider_invoice_id = $txRef;
        $invoice->save();

        $paymentOptions = $isUganda
            ? config('flutterwave.payment_options')
            : config('flutterwave.international_payment_options');

        $result = $this->flutterwave->initiatePayment([
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'redirect_url' => config('flutterwave.redirect_url'),
            'payment_options' => $paymentOptions,
            'customer' => [
                'email' => $user->email,
                'name' => $user->name,
                'phonenumber' => $user->phone_number,
            ],
            'customizations' => [
                'title' => 'PingPin — '.$plan->name.' plan',
                'description' => $plan->interval.'ly subscription',
            ],
            'meta' => [
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'invoice_id' => $invoice->id,
                'product' => 'pingpin',
            ],
        ]);

        if (! ($result['success'] ?? false)) {
            $invoice->status = 'failed';
            $invoice->save();

            return ['success' => false, 'message' => $result['message'] ?? 'Could not start payment.'];
        }

        return [
            'success' => true,
            'payment_link' => $result['link'],
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'invoice_id' => $invoice->id,
        ];
    }

    /** @return array{success: bool, message?: string} */
    public function verify(Company $company, string $txRef, int|string $transactionId): array
    {
        $invoice = PingPinSubscriptionInvoice::where('company_id', $company->id)
            ->where('provider_invoice_id', $txRef)
            ->first();

        if ($invoice === null) {
            return ['success' => false, 'message' => 'Payment reference not found.', 'status' => 404];
        }

        if ($invoice->status === 'paid') {
            return ['success' => true, 'already_paid' => true];
        }

        $verification = $this->flutterwave->verifyTransaction($transactionId);
        if (! ($verification['success'] ?? false)) {
            return ['success' => false, 'message' => $verification['message'] ?? 'Payment could not be verified.'];
        }

        $flwData = $verification['data'];
        if (($flwData['tx_ref'] ?? null) !== $invoice->provider_invoice_id
            || ! $this->flutterwave->transactionSatisfies($flwData, (float) $invoice->amount, $invoice->currency)) {
            return ['success' => false, 'message' => 'Payment verification did not match the expected amount.'];
        }

        $this->fulfill($invoice, $flwData);

        return ['success' => true];
    }

    /**
     * Called from the public webhook route. Signature already verified by
     * the caller — this only handles the payload once trust is established.
     */
    public function handleWebhookPayload(array $eventData): void
    {
        $txRef = $eventData['tx_ref'] ?? null;
        $transactionId = $eventData['id'] ?? null;
        $status = strtolower((string) ($eventData['status'] ?? ''));

        if (! $txRef || ! $transactionId || $status !== 'successful') {
            return;
        }

        $invoice = PingPinSubscriptionInvoice::where('provider_invoice_id', $txRef)->first();
        if ($invoice === null || $invoice->status === 'paid') {
            return;
        }

        $verification = $this->flutterwave->verifyTransaction($transactionId);
        if (! ($verification['success'] ?? false)) {
            return;
        }

        $flwData = $verification['data'];
        if (($flwData['tx_ref'] ?? null) !== $invoice->provider_invoice_id
            || ! $this->flutterwave->transactionSatisfies($flwData, (float) $invoice->amount, $invoice->currency)) {
            Log::warning('PingPin Flutterwave webhook mismatch', ['tx_ref' => $txRef]);

            return;
        }

        $this->fulfill($invoice, $flwData);
    }

    /**
     * Row-locked and re-checked inside the transaction so a verify-call and
     * a webhook racing each other can never both activate the subscription —
     * identical concurrency discipline to BillingController::fulfill().
     */
    private function fulfill(PingPinSubscriptionInvoice $invoice, array $flwData): void
    {
        DB::transaction(function () use ($invoice, $flwData) {
            $locked = PingPinSubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status === 'paid') {
                return;
            }

            $company = Company::find($locked->company_id);
            $plan = PingPinPlan::find((int) data_get($locked->meta, 'plan_id'));

            if ($company === null || $plan === null) {
                Log::error('PingPin Flutterwave fulfill: missing company/plan', ['invoice' => $locked->id]);

                return;
            }

            $subscription = $company->activatePingPinSubscription($plan, 'flutterwave', (string) ($flwData['id'] ?? ''));

            $locked->status = 'paid';
            $locked->subscription_id = $subscription->id;
            $locked->paid_at = now();
            $locked->period_start = $subscription->starts_at;
            $locked->period_end = $subscription->ends_at;
            $locked->meta = array_merge($locked->meta ?? [], [
                'flw_transaction_id' => $flwData['id'] ?? null,
                'flw_flw_ref' => $flwData['flw_ref'] ?? null,
                'payment_type' => $flwData['payment_type'] ?? null,
            ]);
            $locked->save();

            Log::info('PingPin subscription activated via Flutterwave', [
                'company_id' => $company->id, 'plan_id' => $plan->id, 'invoice_id' => $locked->id,
            ]);
        });
    }
}

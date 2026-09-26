<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Services\FlutterwaveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one way a subscription payment is confirmed (POWER_PLAN §4.1). The browser return page
 * (/payment/callback and the new app's /plan/return), the Flutterwave webhook, the API verify
 * endpoint, the mobile-money poll and the hourly reconciliation all call verifyAndFulfill():
 * the transaction is re-verified with Flutterwave, must match the invoice's reference, amount and
 * currency, and is fulfilled once — the invoice row is locked and re-checked so two channels can
 * never activate twice.
 */
class SubscriptionFulfillment
{
    public function __construct(private readonly ?FlutterwaveService $flutterwave = null)
    {
    }

    private function flw(): FlutterwaveService
    {
        return $this->flutterwave ?? app(FlutterwaveService::class);
    }

    /**
     * Verify with Flutterwave (by transaction id when the caller has one, else by reference) and
     * fulfil. `transient` means Flutterwave could not answer: try again later (the webhook answers 5xx).
     *
     * @return array{status: 'paid'|'pending'|'failed'|'mismatch'|'not_found'|'unverified', invoice: ?SubscriptionInvoice, message: string, transient: bool}
     */
    public function verifyAndFulfill(string $txRef, int|string|null $transactionId = null): array
    {
        $out = fn (string $status, ?SubscriptionInvoice $invoice, string $message, bool $transient = false) => compact('status', 'invoice', 'message', 'transient');
        $invoice = $txRef !== '' ? SubscriptionInvoice::where('provider_invoice_id', $txRef)->first() : null;
        if ($invoice === null) {
            return $out('not_found', null, 'We could not find this payment.');
        }
        if (in_array($invoice->status, ['paid', 'refunded'], true)) {
            return $out('paid', $invoice, 'Your payment is confirmed.');
        }

        $v = $transactionId !== null && $transactionId !== '' ? $this->flw()->verifyTransaction($transactionId) : $this->flw()->verifyByReference($txRef);
        if (! $v['success']) {
            return $out('unverified', $invoice, (string) ($v['message'] ?? 'The payment could not be confirmed yet.'), (bool) ($v['transient'] ?? false));
        }
        $data = $v['data'];
        if (($data['tx_ref'] ?? null) !== $invoice->provider_invoice_id) {
            Log::warning('Flutterwave verification reference mismatch', ['tx_ref' => $txRef, 'got' => $data['tx_ref'] ?? null]);

            return $out('mismatch', $invoice, 'This payment does not match the invoice.');
        }
        $status = strtolower((string) ($data['status'] ?? ''));
        if (in_array($status, ['failed', 'cancelled', 'canceled', 'error'], true)) {
            if ($invoice->status === 'pending') {
                $invoice->status = 'failed';
                $invoice->meta = array_merge($invoice->meta ?? [], ['failure' => $status, 'flw_transaction_id' => $data['id'] ?? null]);
                $invoice->save();
            }

            return $out('failed', $invoice->fresh(), 'The payment was declined or cancelled. Nothing was charged.');
        }
        if ($status !== 'successful') {
            return $out('pending', $invoice, 'Waiting for the payment to be approved.');
        }
        if (! $this->flw()->transactionSatisfies($data, (float) $invoice->amount, $invoice->currency)) {
            Log::warning('Flutterwave verification amount mismatch', ['tx_ref' => $txRef, 'amount' => $data['amount'] ?? null, 'currency' => $data['currency'] ?? null]);

            return $out('mismatch', $invoice, 'The amount paid does not match the invoice.');
        }
        $this->fulfill($invoice, $data);

        return $out('paid', $invoice->fresh(), 'Your payment is confirmed and your plan is active.');
    }

    public function fulfill(SubscriptionInvoice $invoice, array $flwData): void
    {
        $this->activate($invoice, $flwData);

        // Offline batches held while the plan was lapsed are applied now (Appendix E "Plan expired").
        $company = Company::withoutGlobalScopes()->find($invoice->company_id);
        $invoice->refresh();
        if ($company && $invoice->status === 'paid' && data_get($invoice->meta, 'notified') === null) {
            $invoice->meta = array_merge($invoice->meta ?? [], ['notified' => true]);
            $invoice->save();
            $company->unsetRelation('subscription');
            app(\App\Services\Notifications\Notifier::class)->notify((int) $company->id, 'billing', 'Payment received',
                'Thank you! '.number_format((float) $invoice->amount).' '.$invoice->currency.' received. '.($company->subscription?->plan?->name ?? 'Your plan').' is active until '.$company->subscription?->ends_at?->toFormattedDateString().'.',
                ['url' => BillingService::billingUrl()]);
        }
        if ($company && $company->hasActiveAccess()) {
            app(\App\Services\Sync\SyncApplier::class)->applyHeld($company);
        }
    }

    private function activate(SubscriptionInvoice $invoice, array $flwData): void
    {
        DB::transaction(function () use ($invoice, $flwData) {
            $locked = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($locked === null || in_array($locked->status, ['paid', 'refunded'], true)) {
                return; // already fulfilled by the other channel
            }

            $company = Company::withoutGlobalScopes()->find($locked->company_id);
            $plan = Plan::find((int) data_get($locked->meta, 'plan_id'));

            if ($company === null || $plan === null) {
                Log::error('Flutterwave fulfill: missing company/plan', ['invoice' => $locked->id]);

                return;
            }

            // Double payment (POWER_PLAN §4.1): only the first paid change resets the period. When another
            // invoice was paid after this one was raised, this one's credit is already spent: it extends.
            $fromNow = (bool) data_get($locked->meta, 'change', false) && ! SubscriptionInvoice::where('company_id', $company->id)
                ->where('id', '!=', $locked->id)->whereIn('status', ['paid', 'refunded'])->where('paid_at', '>', $locked->created_at)->exists();

            $subscription = $company->activateSubscription($plan, 'flutterwave', (string) ($flwData['id'] ?? ''), $fromNow, $locked->interval());

            $card = $flwData['card'] ?? null;
            if (is_array($card) && ! empty($card['token'])) {
                $subscription->meta = array_merge($subscription->meta ?? [], ['card' => ['token' => $card['token'], 'last4' => $card['last_4digits'] ?? null,
                    'type' => $card['type'] ?? null, 'expiry' => $card['expiry'] ?? null, 'email' => data_get($flwData, 'customer.email')]]);
                $subscription->auto_renew = (bool) config('saas.auto_renew', false) || (bool) $subscription->auto_renew;
                $subscription->save();
            }

            $locked->status = 'paid';
            $locked->number = $locked->number ?: 'BP-'.now()->format('Y').'-'.str_pad((string) $locked->id, 6, '0', STR_PAD_LEFT);
            $locked->subscription_id = $subscription->id;
            $locked->paid_at = now();
            $locked->period_start = $locked->interval() === 'year' || $plan->interval === 'year' ? $subscription->ends_at->copy()->subYear() : $subscription->ends_at->copy()->subMonth();
            $locked->period_end = $subscription->ends_at;
            $locked->meta = array_merge($locked->meta ?? [], [
                'flw_transaction_id' => $flwData['id'] ?? null,
                'flw_flw_ref' => $flwData['flw_ref'] ?? null,
                'payment_type' => $flwData['payment_type'] ?? null,
                'extended' => ! $fromNow,
            ]);
            $locked->save();

            BillingEvent::record((int) $company->id, 'paid', null, ['amount' => (float) $locked->amount, 'currency' => $locked->currency, 'plan_id' => $plan->id,
                'interval' => $locked->interval(), 'from_now' => $fromNow, 'ends_at' => $subscription->ends_at?->toIso8601String()], null, $subscription->id, $locked->id);

            Log::info('Subscription activated via Flutterwave', ['company_id' => $company->id, 'plan_id' => $plan->id, 'invoice_id' => $locked->id]);
        });
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Services\FlutterwaveService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Subscription billing via Flutterwave.
 *
 * Ugandan companies (currency UGX) are billed in UGX and offered mobile money +
 * card + bank + USSD. Everyone else is billed in USD by card.
 *
 * Payment is confirmed two ways, both idempotent and both re-verifying the
 * transaction server-side against the amount/currency we billed:
 *   1. /subscription/verify — called by the client after the redirect.
 *   2. /webhooks/flutterwave — Flutterwave's server-to-server callback.
 */
class BillingController extends Controller
{
    use ApiResponse;

    public function __construct(private FlutterwaveService $flutterwave)
    {
    }

    /**
     * Public list of purchasable plans, with both UGX and USD pricing.
     */
    public function plans(Request $request)
    {
        $plans = Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'price_usd' => (float) $p->price,
                'price_ugx' => (float) $p->price_ugx,
                'interval' => $p->interval,
                'trial_days' => $p->trial_days,
                'features' => $p->features,
                'limits' => $p->limits,
            ]);

        return $this->success($plans, 'Plans loaded.');
    }

    /**
     * The authenticated company's current subscription + recent invoices.
     */
    public function current(Request $request)
    {
        /** @var Company $company */
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        $subscription = $company->subscription;

        $invoices = SubscriptionInvoice::where('company_id', $company->id)
            ->orderByDesc('id')->limit(20)->get();

        return $this->success([
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'plan' => $subscription->plan?->only(['id', 'name', 'slug', 'interval', 'features', 'limits']),
                'trial_ends_at' => optional($subscription->trial_ends_at)->toIso8601String(),
                'ends_at' => optional($subscription->ends_at)->toIso8601String(),
                'is_active' => $subscription->isActive(),
            ] : null,
            'has_active_access' => $company->hasActiveAccess(),
            'invoices' => $invoices,
        ], 'Subscription loaded.');
    }

    /**
     * Start a payment for a plan. Returns a Flutterwave hosted-checkout link.
     */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'plan_id' => [
                'required',
                Rule::exists('plans', 'id')->where(fn ($q) => $q->where('is_active', true)->where('is_public', true)),
            ],
        ]);

        /** @var Company $company */
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        $user = $request->user();
        $plan = Plan::findOrFail($data['plan_id']);

        $isUganda = $company->isUgandaBilling();
        ['amount' => $amount, 'currency' => $currency] = $plan->chargeFor($isUganda);

        if ($amount <= 0) {
            return $this->error('This plan is not available for purchase.', 422);
        }

        // Create a pending invoice first so we have a stable id for the tx_ref.
        $invoice = SubscriptionInvoice::create([
            'company_id' => $company->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'provider' => 'flutterwave',
            'meta' => ['plan_id' => $plan->id, 'is_uganda' => $isUganda],
        ]);

        $txRef = 'BPRO-'.$invoice->id.'-'.Str::upper(Str::random(10));
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
                'title' => config('app.name').' — '.$plan->name.' plan',
                'description' => $plan->interval.'ly subscription',
            ],
            'meta' => [
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'invoice_id' => $invoice->id,
            ],
        ]);

        if (! ($result['success'] ?? false)) {
            $invoice->status = 'failed';
            $invoice->save();

            return $this->error($result['message'] ?? 'Could not start payment.', 502);
        }

        return $this->success([
            'payment_link' => $result['link'],
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'invoice_id' => $invoice->id,
        ], 'Payment initiated. Redirect the customer to the payment link.');
    }

    /**
     * Client-driven verification after the payment redirect.
     */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'transaction_id' => ['required'],
            'tx_ref' => ['required', 'string'],
        ]);

        /** @var Company $company */
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);

        $invoice = SubscriptionInvoice::where('company_id', $company->id)
            ->where('provider_invoice_id', $data['tx_ref'])
            ->first();

        if ($invoice === null) {
            return $this->notFound('Payment reference not found.');
        }

        if ($invoice->status === 'paid') {
            return $this->success($this->subscriptionPayload($company->fresh()), 'Payment already confirmed.');
        }

        $verification = $this->flutterwave->verifyTransaction($data['transaction_id']);

        if (! ($verification['success'] ?? false)) {
            return $this->error($verification['message'] ?? 'Payment could not be verified.', 422);
        }

        $flwData = $verification['data'];

        // The verified transaction must match THIS invoice's reference, amount and currency.
        if (($flwData['tx_ref'] ?? null) !== $invoice->provider_invoice_id
            || ! $this->flutterwave->transactionSatisfies($flwData, (float) $invoice->amount, $invoice->currency)) {
            return $this->error('Payment verification did not match the expected amount.', 422);
        }

        $this->fulfill($invoice, $flwData);

        return $this->success($this->subscriptionPayload($company->fresh()), 'Payment confirmed. Subscription activated.');
    }

    /**
     * Flutterwave server-to-server webhook. Public, authenticated by signature.
     */
    public function webhook(Request $request)
    {
        $signature = $request->header('verif-hash');

        if (! $this->flutterwave->verifyWebhookSignature($signature)) {
            Log::warning('Flutterwave webhook rejected: bad signature');

            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $eventData = $payload['data'] ?? [];
        $txRef = $eventData['tx_ref'] ?? null;
        $transactionId = $eventData['id'] ?? null;
        $status = strtolower((string) ($eventData['status'] ?? ''));

        // Acknowledge everything we can't act on (Flutterwave retries on non-2xx).
        if (! $txRef || ! $transactionId || $status !== 'successful') {
            return response()->json(['status' => 'ignored'], 200);
        }

        $invoice = SubscriptionInvoice::where('provider_invoice_id', $txRef)->first();

        if ($invoice === null || $invoice->status === 'paid') {
            return response()->json(['status' => 'ok'], 200);
        }

        // Never trust the webhook body alone — re-verify with the API.
        $verification = $this->flutterwave->verifyTransaction($transactionId);
        if (! ($verification['success'] ?? false)) {
            return response()->json(['status' => 'unverified'], 200);
        }

        $flwData = $verification['data'];
        if (($flwData['tx_ref'] ?? null) !== $invoice->provider_invoice_id
            || ! $this->flutterwave->transactionSatisfies($flwData, (float) $invoice->amount, $invoice->currency)) {
            Log::warning('Flutterwave webhook mismatch', ['tx_ref' => $txRef]);

            return response()->json(['status' => 'mismatch'], 200);
        }

        $this->fulfill($invoice, $flwData);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Mark an invoice paid and activate the subscription. Idempotent and
     * transactional: the invoice row is locked and re-checked to prevent a
     * webhook + verify race from activating twice.
     */
    private function fulfill(SubscriptionInvoice $invoice, array $flwData): void
    {
        DB::transaction(function () use ($invoice, $flwData) {
            /** @var SubscriptionInvoice $locked */
            $locked = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status === 'paid') {
                return; // already fulfilled by the other channel
            }

            $company = Company::find($locked->company_id);
            $planId = (int) data_get($locked->meta, 'plan_id');
            $plan = Plan::find($planId);

            if ($company === null || $plan === null) {
                Log::error('Flutterwave fulfill: missing company/plan', ['invoice' => $locked->id]);

                return;
            }

            $subscription = $company->activateSubscription($plan, 'flutterwave', (string) ($flwData['id'] ?? ''));

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

            Log::info('Subscription activated via Flutterwave', [
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'invoice_id' => $locked->id,
            ]);
        });
    }

    private function subscriptionPayload(Company $company): array
    {
        $subscription = $company->subscription;

        return [
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'plan' => $subscription->plan?->only(['id', 'name', 'slug', 'interval']),
                'ends_at' => optional($subscription->ends_at)->toIso8601String(),
                'is_active' => $subscription->isActive(),
            ] : null,
            'has_active_access' => $company->hasActiveAccess(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\SubscriptionFulfillment;
use App\Services\FlutterwaveService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
                'price_ugx_annual' => $p->isFree() ? 0.0 : $p->chargeIn('UGX', 'year')['amount'],
                'price_usd_annual' => $p->isFree() ? 0.0 : $p->chargeIn('USD', 'year')['amount'],
                'prices' => $p->prices,
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
                'canceled_at' => optional($subscription->canceled_at)->toIso8601String(),
                'is_free' => (bool) $subscription->plan?->isFree(),
                'billing_interval' => $subscription->interval(),
                'pending_plan' => $subscription->pendingPlan?->only(['id', 'name', 'slug']),
                'pending_change_at' => optional($subscription->pending_change_at)->toIso8601String(),
                'auto_renew' => (bool) $subscription->auto_renew,
                'card' => ($c = $subscription->savedCard()) ? ['last4' => $c['last4'] ?? null, 'type' => $c['type'] ?? null, 'expiry' => $c['expiry'] ?? null] : null,
            ] : null,
            'has_active_access' => $company->hasActiveAccess(),
            'access_state' => $company->accessState(),
            'usage' => (new \App\Services\Billing\Quotas())->usage($company),
            'currency' => app(\App\Services\Billing\BillingService::class)->currency($company),
            'can_manage' => $this->canManageBilling($company, $request->user()),
            'billing_url' => \App\Services\Billing\BillingService::billingUrl(),
            'momo_networks' => app(\App\Services\Billing\BillingService::class)->momoNetworks($company),
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
            'interval' => ['nullable', 'in:month,year'],
        ]);

        /** @var Company $company */
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        $user = $request->user();
        if (! $this->canManageBilling($company, $user)) {
            return $this->forbidden('Only the company owner can change the subscription.');
        }
        $plan = Plan::findOrFail($data['plan_id']);
        try {
            $r = app(\App\Services\Billing\BillingService::class)->checkout($company, $user, $plan, $data['interval'] ?? 'month');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->ruleError($e);
        }
        if ($r['immediate']) {
            return $this->success($this->subscriptionPayload($company->fresh()) + ['quote' => $r['quote'], 'payment_link' => null], 'Plan changed. Your unused days paid for it.');
        }

        return $this->success([
            'payment_link' => $r['payment_link'],
            'tx_ref' => $r['tx_ref'],
            'amount' => $r['quote']['amount'],
            'currency' => $r['quote']['currency'],
            'invoice_id' => $r['invoice_id'],
            'quote' => $r['quote'],
        ], 'Payment initiated. Redirect the customer to the payment link.');
    }

    /** GET subscription/quote?plan_id= — what a change costs now, after credit for unused days. */
    public function quote(Request $request)
    {
        $data = $request->validate(['plan_id' => ['required', Rule::exists('plans', 'id')->where(fn ($q) => $q->where('is_active', true)->where('is_public', true))],
            'interval' => ['nullable', 'in:month,year']]);
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        try {
            return $this->success(app(\App\Services\Billing\BillingService::class)->quote($company, Plan::findOrFail($data['plan_id']), $data['interval'] ?? 'month'), 'Quote.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->ruleError($e);
        }
    }

    /** POST subscription/cancel — stays on the plan until the period ends, then Free. */
    public function cancel(Request $request)
    {
        return $this->act($request, fn ($billing, $company) => $billing->cancel($company));
    }

    public function resume(Request $request)
    {
        return $this->act($request, fn ($billing, $company) => $billing->resume($company));
    }

    /** POST subscription/momo — a mobile-money prompt on the owner's phone; poll GET subscription/payments/{invoice}. */
    public function chargeMomo(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where(fn ($q) => $q->where('is_active', true)->where('is_public', true))],
            'interval' => ['nullable', 'in:month,year'],
            'phone' => ['required', 'string', 'max:20'],
            'network' => ['nullable', 'string', 'max:20'],
        ]);
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        if (! $this->canManageBilling($company, $request->user())) {
            return $this->forbidden('Only the company owner can change the subscription.');
        }
        try {
            $r = app(\App\Services\Billing\BillingService::class)->chargeMobileMoney($company, $request->user(), Plan::findOrFail($data['plan_id']), $data['phone'], $data['network'] ?? null, $data['interval'] ?? 'month');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->ruleError($e);
        }

        return $this->success(['status' => $r['status'], 'invoice_id' => $r['invoice_id'], 'tx_ref' => $r['tx_ref'], 'redirect' => $r['redirect'], 'quote' => $r['quote']],
            $r['immediate'] ? 'Plan changed. Your unused days paid for it.' : 'Approve the payment on your phone.');
    }

    /** GET subscription/payments/{id} — where a payment stands (asks Flutterwave while pending). */
    public function paymentStatus(Request $request, $id)
    {
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        if (! $this->canManageBilling($company, $request->user())) {
            return $this->forbidden('Only the company owner can see payments.');
        }
        try {
            $r = app(\App\Services\Billing\BillingService::class)->paymentStatus($company, (int) $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('Payment not found.');
        }

        return $this->success(['status' => $r['status'], 'invoice_id' => $r['invoice']->id] + ($r['status'] === 'paid' ? $this->subscriptionPayload($company->fresh()) : []), $r['message'] ?: ucfirst($r['status']).'.');
    }

    /** POST subscription/schedule-change — move to another plan when the paid period ends ("at the end of my period"). */
    public function scheduleChange(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where(fn ($q) => $q->where('is_active', true)->where('is_public', true))],
            'interval' => ['nullable', 'in:month,year'],
        ]);

        return $this->act($request, fn ($billing, $company) => $billing->scheduleChange($company, Plan::findOrFail($data['plan_id']), $data['interval'] ?? 'month'));
    }

    /** POST subscription/cancel-change — keep the current plan after all. */
    public function cancelChange(Request $request)
    {
        return $this->act($request, fn ($billing, $company) => $billing->cancelScheduledChange($company));
    }

    /** GET subscription/invoices/{id}.pdf — billing permission only. */
    public function invoicePdf(Request $request, $id, \App\Services\Billing\InvoicePdf $pdf)
    {
        try {
            return $pdf->response($pdf->find($request->user(), (int) $id));
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $e->errorCode() === 'forbidden' ? $this->forbidden($e->getMessage()) : $this->notFound($e->getMessage());
        }
    }

    private function act(Request $request, callable $fn)
    {
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        if (! $this->canManageBilling($company, $request->user())) {
            return $this->forbidden('Only the company owner can change the subscription.');
        }
        try {
            $fn(app(\App\Services\Billing\BillingService::class), $company);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->ruleError($e);
        }
        $company->unsetRelation('subscription');

        return $this->current($request);
    }

    private function ruleError(\App\Exceptions\BusinessRuleException $e)
    {
        return $this->error($e->getMessage(), match ($e->errorCode()) {
            'gateway' => 502, 'suspended' => 403, default => 422
        }, $e->toErrors());
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

        $r = app(SubscriptionFulfillment::class)->verifyAndFulfill($invoice->provider_invoice_id, $data['transaction_id']);

        return match ($r['status']) {
            'paid' => $this->success($this->subscriptionPayload($company->fresh()), $invoice->status === 'paid' ? 'Payment already confirmed.' : 'Payment confirmed. Subscription activated.'),
            'mismatch' => $this->error('Payment verification did not match the expected amount.', 422),
            'unverified' => $this->error($r['message'] ?: 'Payment could not be verified.', $r['transient'] ? 503 : 422),
            default => $this->error($r['message'], 422),
        };
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

        // Mobile-money request-to-pay for a shop's sale (Part E3): verified with Flutterwave, recorded once.
        if (str_starts_with((string) $txRef, 'MOMO-')) {
            app(\App\Services\Engage\MomoCollections::class)->settle((string) $txRef);

            return response()->json(['status' => 'ok'], 200);
        }

        // Never trust the webhook body alone — re-verify with the API (SubscriptionFulfillment::verifyAndFulfill).
        $r = app(SubscriptionFulfillment::class)->verifyAndFulfill((string) $txRef, $transactionId);
        if ($r['status'] === 'unverified' && $r['transient']) {
            // Flutterwave could not answer: 5xx so it retries (the hourly reconciliation is the safety net).
            return response()->json(['status' => 'retry'], 503);
        }
        if ($r['status'] === 'mismatch') {
            Log::warning('Flutterwave webhook mismatch', ['tx_ref' => $txRef]);
        }

        return response()->json(['status' => match ($r['status']) {
            'paid' => 'ok', 'not_found' => 'ok', default => $r['status']
        }], 200);
    }

    /** Billing is owner-only by default (plan C5 `billing` permission). */
    private function canManageBilling(Company $company, $user): bool
    {
        return \App\Services\Team\Permissions::can($user, 'billing');
    }

    private function subscriptionPayload(Company $company): array
    {
        $subscription = $company->subscription;

        return [
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'plan' => $subscription->plan?->only(['id', 'name', 'slug', 'interval']),
                'billing_interval' => $subscription->interval(),
                'ends_at' => optional($subscription->ends_at)->toIso8601String(),
                'is_active' => $subscription->isActive(),
            ] : null,
            'has_active_access' => $company->hasActiveAccess(),
        ];
    }
}

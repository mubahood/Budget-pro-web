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
            ] : null,
            'has_active_access' => $company->hasActiveAccess(),
            'access_state' => $company->accessState(),
            'usage' => (new \App\Services\Billing\Quotas())->usage($company),
            'currency' => app(\App\Services\Billing\BillingService::class)->currency($company),
            'can_manage' => $this->canManageBilling($company, $request->user()),
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
        if (! $this->canManageBilling($company, $user)) {
            return $this->forbidden('Only the company owner can change the subscription.');
        }
        $plan = Plan::findOrFail($data['plan_id']);
        try {
            $r = app(\App\Services\Billing\BillingService::class)->checkout($company, $user, $plan);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), $e->errorCode() === 'gateway' ? 502 : 422, $e->toErrors());
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
        $data = $request->validate(['plan_id' => ['required', Rule::exists('plans', 'id')->where(fn ($q) => $q->where('is_active', true)->where('is_public', true))]]);
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        try {
            return $this->success(app(\App\Services\Billing\BillingService::class)->quote($company, Plan::findOrFail($data['plan_id'])), 'Quote.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }
    }

    /** POST subscription/cancel — stays on the plan until the period ends, then Free. */
    public function cancel(Request $request)
    {
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        try {
            app(\App\Services\Billing\BillingService::class)->cancel($company);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->current($request);
    }

    public function resume(Request $request)
    {
        $company = $request->attributes->get('company') ?? Company::find($request->user()->company_id);
        try {
            app(\App\Services\Billing\BillingService::class)->resume($company);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->current($request);
    }

    /** GET subscription/invoices/{id}.pdf */
    public function invoicePdf(Request $request, $id)
    {
        $companyId = (int) $request->user()->company_id;
        $invoice = SubscriptionInvoice::where('company_id', $companyId)->where('status', 'paid')->find($id);
        if ($invoice === null) {
            return $this->notFound('Invoice not found.');
        }
        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML(view('reports.subscription-invoice', ['invoice' => $invoice, 'company' => Company::withoutGlobalScopes()->find($companyId), 'plan' => Plan::find(data_get($invoice->meta, 'plan_id'))])->render());

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="invoice-'.($invoice->number ?: $invoice->id).'.pdf"']);
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

        if (! $verification['success']) {
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

        // Mobile-money request-to-pay for a shop's sale (Part E3): verified with Flutterwave, recorded once.
        if (str_starts_with((string) $txRef, 'MOMO-')) {
            app(\App\Services\Engage\MomoCollections::class)->settle((string) $txRef);

            return response()->json(['status' => 'ok'], 200);
        }

        $invoice = SubscriptionInvoice::where('provider_invoice_id', $txRef)->first();

        if ($invoice === null || $invoice->status === 'paid') {
            return response()->json(['status' => 'ok'], 200);
        }

        // Never trust the webhook body alone — re-verify with the API.
        $verification = $this->flutterwave->verifyTransaction($transactionId);
        if (! $verification['success']) {
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

    private function fulfill(SubscriptionInvoice $invoice, array $flwData): void
    {
        app(SubscriptionFulfillment::class)->fulfill($invoice, $flwData);
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
                'ends_at' => optional($subscription->ends_at)->toIso8601String(),
                'is_active' => $subscription->isActive(),
            ] : null,
            'has_active_access' => $company->hasActiveAccess(),
        ];
    }
}

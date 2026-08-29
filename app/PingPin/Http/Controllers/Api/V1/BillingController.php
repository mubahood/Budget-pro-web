<?php

namespace App\PingPin\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PingPinPlan;
use App\Models\PingPinSubscriptionInvoice;
use App\PingPin\Services\PingPinBillingService;
use App\Services\FlutterwaveService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    use ApiResponse;

    public function __construct(private PingPinBillingService $billing, private FlutterwaveService $flutterwave)
    {
    }

    /** Public: the 3 purchasable plans + trial, with both UGX and USD pricing. */
    public function plans(Request $request)
    {
        $plans = PingPinPlan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PingPinPlan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'price_usd' => (float) $p->price,
                'price_ugx' => (float) $p->price_ugx,
                'interval' => $p->interval,
                'features' => $p->features,
                'limits' => $p->limits,
            ]);

        return $this->success($plans, 'Plans loaded.');
    }

    /** pingpin.member-gated — {company} route-bound. */
    public function current(Request $request, Company $company)
    {
        $subscription = $company->pingPinSubscription;
        $invoices = PingPinSubscriptionInvoice::where('company_id', $company->id)->orderByDesc('id')->limit(20)->get();

        return $this->success([
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'plan' => $subscription->plan?->only(['id', 'name', 'slug', 'interval', 'features', 'limits']),
                'trial_ends_at' => optional($subscription->trial_ends_at)->toIso8601String(),
                'ends_at' => optional($subscription->ends_at)->toIso8601String(),
                'is_active' => $subscription->isActive(),
            ] : null,
            'invoices' => $invoices,
        ], 'Subscription loaded.');
    }

    public function checkout(Request $request, Company $company)
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('pingpin_plans', 'id')->where(fn ($q) => $q->where('is_active', true)->where('is_public', true))],
        ]);

        $plan = PingPinPlan::findOrFail($data['plan_id']);
        $result = $this->billing->checkout($company, $plan, $request->user());

        if (! ($result['success'] ?? false)) {
            return $this->error($result['message'] ?? 'Could not start payment.', 502);
        }

        return $this->success([
            'payment_link' => $result['payment_link'],
            'tx_ref' => $result['tx_ref'],
            'amount' => $result['amount'],
            'currency' => $result['currency'],
            'invoice_id' => $result['invoice_id'],
        ], 'Payment initiated. Redirect the customer to the payment link.');
    }

    public function verify(Request $request, Company $company)
    {
        $data = $request->validate(['transaction_id' => ['required'], 'tx_ref' => ['required', 'string']]);

        $result = $this->billing->verify($company, $data['tx_ref'], $data['transaction_id']);

        if (! ($result['success'] ?? false)) {
            return $this->error($result['message'] ?? 'Payment could not be verified.', $result['status'] ?? 422);
        }

        return $this->success($this->subscriptionPayload($company->fresh()), 'Payment confirmed. Subscription activated.');
    }

    /** Public, signature-authenticated. */
    public function webhook(Request $request)
    {
        $signature = $request->header('verif-hash');

        if (! $this->flutterwave->verifyWebhookSignature($signature)) {
            Log::warning('PingPin Flutterwave webhook rejected: bad signature');

            return response()->json(['status' => 'unauthorized'], 401);
        }

        $eventData = $request->input('data', []);
        $this->billing->handleWebhookPayload($eventData);

        // Always 200 for anything signature-valid — Flutterwave retries on
        // non-2xx, and "ignored/unverified/mismatch" are all handled inside
        // handleWebhookPayload() by simply not fulfilling; there's nothing
        // further for the caller to branch on.
        return response()->json(['status' => 'ok'], 200);
    }

    private function subscriptionPayload(Company $company): array
    {
        $subscription = $company->pingPinSubscription;

        return [
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'plan' => $subscription->plan?->only(['id', 'name', 'slug', 'interval']),
                'ends_at' => optional($subscription->ends_at)->toIso8601String(),
                'is_active' => $subscription->isActive(),
            ] : null,
        ];
    }
}

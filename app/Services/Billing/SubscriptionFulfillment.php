<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marks an invoice paid and activates the subscription. Shared by the API
 * verify endpoint, the Flutterwave webhook and the browser return URL
 * (/payment/callback, P0-14). Idempotent and transactional: the invoice row
 * is locked and re-checked so two channels can never activate twice.
 */
class SubscriptionFulfillment
{
    public function fulfill(SubscriptionInvoice $invoice, array $flwData): void
    {
        $this->activate($invoice, $flwData);

        // Offline batches held while the plan was lapsed are applied now (Appendix E "Plan expired").
        $company = Company::find($invoice->company_id);
        if ($company && $company->hasActiveAccess()) {
            app(\App\Services\Sync\SyncApplier::class)->applyHeld($company);
        }
    }

    private function activate(SubscriptionInvoice $invoice, array $flwData): void
    {
        DB::transaction(function () use ($invoice, $flwData) {
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
}

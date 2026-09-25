<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Notifications\Notifier;

/**
 * Plan changes with proration, cancel/resume (plan C8, P3-6).
 * Unused days of a paid plan become credit toward the new plan; if the credit
 * covers the new price the change is immediate and the leftover becomes extra days.
 */
class BillingService
{
    public function currency(Company $company): string
    {
        return strtoupper((string) ($company->currency ?: config('saas.default_currency', 'UGX')));
    }

    /** @return array{amount: float, currency: string, credit: float, full_price: float, change: bool, immediate: bool, ends_at: ?string, payment_options: string} */
    public function quote(Company $company, Plan $plan): array
    {
        if ($plan->isFree()) {
            throw BusinessRuleException::make('free_plan', 'The Free plan needs no payment. Cancel your plan to move to Free at the end of the period.');
        }
        $currency = $this->currency($company);
        ['amount' => $price, 'currency' => $chargeCurrency] = $plan->chargeIn($currency);
        if ($price <= 0) {
            throw BusinessRuleException::make('not_for_sale', 'This plan is not available for purchase.');
        }
        $sub = $company->subscription;
        $change = $sub !== null && $sub->plan_id !== $plan->id && $this->isPaidAndRunning($sub);
        $credit = $change ? $this->credit($sub, $chargeCurrency) : 0.0;
        $amount = round(max(0, $price - $credit), 2);
        $immediate = $change && $amount <= 0;
        $endsAt = null;
        if ($immediate) {
            $days = (int) floor($plan->periodDays() * $credit / $price);
            $endsAt = now()->addDays($days)->toIso8601String();
        }

        return [
            'amount' => $amount, 'currency' => $chargeCurrency, 'credit' => round($credit, 2), 'full_price' => $price, 'change' => $change,
            'immediate' => $immediate, 'ends_at' => $endsAt, 'payment_options' => $this->paymentOptions($chargeCurrency),
        ];
    }

    /**
     * Start paying for a plan: a change fully covered by credit applies at once,
     * otherwise a pending invoice + Flutterwave hosted checkout (mobile money/card).
     *
     * @return array{immediate: bool, quote: array, payment_link: ?string, tx_ref: ?string, invoice_id: ?int}
     */
    public function checkout(Company $company, \App\Models\User $user, Plan $plan): array
    {
        $quote = $this->quote($company, $plan);
        if ($quote['immediate']) {
            $this->applyImmediateChange($company, $plan, $quote);

            return ['immediate' => true, 'quote' => $quote, 'payment_link' => null, 'tx_ref' => null, 'invoice_id' => null];
        }
        $invoice = \App\Models\SubscriptionInvoice::create([
            'company_id' => $company->id, 'amount' => $quote['amount'], 'currency' => $quote['currency'], 'status' => 'pending', 'provider' => 'flutterwave',
            'meta' => ['plan_id' => $plan->id, 'is_uganda' => $quote['currency'] === 'UGX', 'change' => $quote['change'], 'credit' => $quote['credit'], 'full_price' => $quote['full_price']],
        ]);
        $txRef = 'BPRO-'.$invoice->id.'-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10));
        $invoice->provider_invoice_id = $txRef;
        $invoice->save();

        $result = app(\App\Services\FlutterwaveService::class)->initiatePayment([
            'tx_ref' => $txRef, 'amount' => $quote['amount'], 'currency' => $quote['currency'],
            'redirect_url' => config('flutterwave.redirect_url'), 'payment_options' => $quote['payment_options'],
            'customer' => ['email' => $user->email ?: $company->email, 'name' => $user->name, 'phonenumber' => $user->phone_e164 ?: $user->phone_number],
            'customizations' => ['title' => config('app.name').' — '.$plan->name.' plan', 'description' => $plan->interval.'ly subscription'],
            'meta' => ['company_id' => $company->id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id],
        ]);
        if (! $result['success']) {
            $invoice->status = 'failed';
            $invoice->save();
            throw BusinessRuleException::make('gateway', $result['message'] ?? 'Could not start payment.');
        }

        return ['immediate' => false, 'quote' => $quote, 'payment_link' => $result['link'], 'tx_ref' => $txRef, 'invoice_id' => $invoice->id];
    }

    public function paymentOptions(string $currency): string
    {
        return match ($currency) {
            'UGX' => (string) config('flutterwave.payment_options'),
            default => (string) (config("flutterwave.local_payment_options.{$currency}") ?? config('flutterwave.international_payment_options')),
        };
    }

    private function isPaidAndRunning(Subscription $sub): bool
    {
        return in_array($sub->status, ['active', 'canceled'], true) && $sub->ends_at !== null && $sub->ends_at->isFuture() && $sub->plan !== null && ! $sub->plan->isFree();
    }

    /** Value of the unused days of the current plan, in the charge currency. */
    private function credit(Subscription $sub, string $currency): float
    {
        $plan = $sub->plan;
        ['amount' => $paid, 'currency' => $cur] = $plan->chargeIn($currency);
        if ($cur !== $currency || $paid <= 0) {
            return 0.0;
        }
        $remaining = now()->diffInSeconds($sub->ends_at) / 86400;

        return max(0.0, $paid * min(1.0, $remaining / $plan->periodDays()));
    }

    /** A change fully paid by credit: switch now, no payment. */
    public function applyImmediateChange(Company $company, Plan $plan, array $quote): Subscription
    {
        $sub = $company->subscription;
        $sub->plan_id = $plan->id;
        $sub->status = 'active';
        $sub->canceled_at = null;
        $sub->ends_at = \Illuminate\Support\Carbon::parse($quote['ends_at']);
        $sub->save();
        $company->license_expire = $sub->ends_at;
        $company->saveQuietly();
        app(Notifier::class)->notify((int) $company->id, 'billing', 'Plan changed', "You are now on {$plan->name}, paid by your unused days until ".$sub->ends_at->toFormattedDateString().'.');

        return $sub;
    }

    /** Cancel at the end of the paid period; the shop then moves to the Free plan. */
    public function cancel(Company $company): Subscription
    {
        $sub = $company->subscription;
        if ($sub === null || ! $this->isPaidAndRunning($sub) || $sub->status === 'canceled') {
            throw BusinessRuleException::make('nothing_to_cancel', 'There is no paid plan to cancel.');
        }
        $sub->status = 'canceled';
        $sub->canceled_at = now();
        $sub->save();

        return $sub;
    }

    public function resume(Company $company): Subscription
    {
        $sub = $company->subscription;
        if ($sub === null || $sub->status !== 'canceled' || $sub->ends_at === null || $sub->ends_at->isPast()) {
            throw BusinessRuleException::make('nothing_to_resume', 'There is no cancelled plan to resume.');
        }
        $sub->status = 'active';
        $sub->canceled_at = null;
        $sub->save();

        return $sub;
    }
}

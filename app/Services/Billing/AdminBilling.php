<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\BillingEvent;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\FlutterwaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform-admin billing actions (POWER_PLAN §4.2): comp a plan until a date, extend a trial, extend
 * a period, record a manual (cash/bank) payment, refund an invoice. Every action needs a reason and
 * is written to billing_events (who, what, why) — no more raw edits of a shop's subscription.
 */
class AdminBilling
{
    private function subscription(Company $company): Subscription
    {
        $company->unsetRelation('subscription');

        return $company->subscription ?? new Subscription(['company_id' => $company->id, 'status' => 'active', 'starts_at' => now()]);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw BusinessRuleException::make('reason_required', 'Say why (a few words) — it is kept in the billing history.');
        }

        return $reason;
    }

    private function syncLicence(Company $company, Subscription $sub): void
    {
        $company->license_expire = $sub->status === 'trialing' ? ($sub->trial_ends_at ?? $sub->ends_at) : $sub->ends_at;
        $company->saveQuietly();
        $company->unsetRelation('subscription');
    }

    /** Give a plan free of charge until a date (e.g. a pilot shop, a partner). */
    public function comp(Company $company, Plan $plan, Carbon $until, string $reason, User $actor): Subscription
    {
        $reason = $this->reason($reason);
        if ($until->isPast()) {
            throw BusinessRuleException::make('invalid_date', 'Choose a date in the future.');
        }

        return DB::transaction(function () use ($company, $plan, $until, $reason, $actor) {
            $sub = $this->subscription($company);
            $before = $sub->only(['plan_id', 'status', 'ends_at']);
            $sub->fill(['plan_id' => $plan->id, 'status' => 'active', 'ends_at' => $until->copy()->endOfDay(), 'trial_ends_at' => null, 'canceled_at' => null,
                'provider' => 'comp', 'pending_plan_id' => null, 'pending_change_at' => null, 'auto_renew' => false]);
            $sub->starts_at ??= now();
            $sub->save();
            $this->syncLicence($company, $sub);
            BillingEvent::record((int) $company->id, 'comp', $actor->id, ['plan_id' => $plan->id, 'until' => $sub->ends_at->toIso8601String(), 'before' => $before], $reason, $sub->id);

            return $sub;
        });
    }

    public function extendTrial(Company $company, int $days, string $reason, User $actor): Subscription
    {
        $reason = $this->reason($reason);
        $sub = $this->subscription($company);
        if ($sub->status !== 'trialing' || $days < 1 || $days > 90) {
            throw BusinessRuleException::make('not_trialing', $sub->status !== 'trialing' ? 'This shop is not on a trial.' : 'Extend by 1 to 90 days.');
        }
        $from = ($sub->trial_ends_at ?? $sub->ends_at ?? now())->copy();
        $end = ($from->isFuture() ? $from : now())->addDays($days);
        $sub->trial_ends_at = $end;
        if ($sub->ends_at !== null) {
            $sub->ends_at = $end;
        }
        $sub->save();
        $this->syncLicence($company, $sub);
        BillingEvent::record((int) $company->id, 'trial_extended', $actor->id, ['days' => $days, 'from' => $from->toIso8601String(), 'to' => $end->toIso8601String()], $reason, $sub->id);

        return $sub;
    }

    /** Add days to the current paid period (from its end, or from today if it has ended). */
    public function extendPeriod(Company $company, int $days, string $reason, User $actor): Subscription
    {
        $reason = $this->reason($reason);
        $sub = $this->subscription($company);
        if (! $sub->exists || $sub->plan === null || $sub->plan->isFree() || $days < 1 || $days > 366) {
            throw BusinessRuleException::make('not_paid', $days < 1 || $days > 366 ? 'Extend by 1 to 366 days.' : 'This shop is not on a paid plan. Comp a plan instead.');
        }
        $from = $sub->ends_at?->copy();
        $sub->ends_at = ($from !== null && $from->isFuture() ? $from->copy() : now())->addDays($days);
        if (in_array($sub->status, ['past_due', 'expired'], true)) {
            $sub->status = 'active';
        }
        $sub->save();
        $this->syncLicence($company, $sub);
        BillingEvent::record((int) $company->id, 'period_extended', $actor->id, ['days' => $days, 'from' => $from?->toIso8601String(), 'to' => $sub->ends_at->toIso8601String()], $reason, $sub->id);

        return $sub;
    }

    /** Cash or bank payment received outside Flutterwave: a paid invoice + the period, exactly like an online payment. */
    public function recordManualPayment(Company $company, Plan $plan, string $interval, float $amount, string $currency, string $method, ?string $reference, string $reason, User $actor): SubscriptionInvoice
    {
        $reason = $this->reason($reason);
        if (! in_array($method, ['cash', 'bank', 'mobile_money', 'other'], true) || $amount <= 0 || $plan->isFree()) {
            throw BusinessRuleException::make('invalid_payment', $plan->isFree() ? 'The Free plan needs no payment.' : 'Enter the amount received and how it was paid.');
        }
        $interval = BillingService::interval($interval);

        return DB::transaction(function () use ($company, $plan, $interval, $amount, $currency, $method, $reference, $reason, $actor) {
            $rate = (float) config('saas.invoice.tax_rate', 0);
            $sub = $company->activateSubscription($plan, 'manual', $reference, false, $interval);
            $invoice = SubscriptionInvoice::create([
                'company_id' => $company->id, 'subscription_id' => $sub->id, 'amount' => round($amount, 2), 'currency' => strtoupper($currency), 'status' => 'paid',
                'provider' => 'manual', 'provider_invoice_id' => 'MAN-'.$company->id.'-'.Str::upper(Str::random(8)), 'paid_at' => now(),
                'period_end' => $sub->ends_at, 'period_start' => $interval === 'year' ? $sub->ends_at->copy()->subYear() : $sub->ends_at->copy()->subMonth(),
                'tax_rate' => $rate, 'tax_amount' => app(BillingService::class)->taxIn($amount, $rate),
                'meta' => ['plan_id' => $plan->id, 'interval' => $interval, 'method' => $method, 'payment_type' => $method, 'reference' => $reference, 'full_price' => round($amount, 2), 'recorded_by' => $actor->id],
            ]);
            $invoice->number = 'BP-'.now()->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
            $invoice->save();
            BillingEvent::record((int) $company->id, 'manual_payment', $actor->id, ['amount' => $amount, 'currency' => strtoupper($currency), 'method' => $method, 'reference' => $reference,
                'plan_id' => $plan->id, 'interval' => $interval, 'ends_at' => $sub->ends_at->toIso8601String()], $reason, $sub->id, $invoice->id);
            $company->unsetRelation('subscription');

            return $invoice;
        });
    }

    /**
     * Refund a paid invoice, in full or in part. With $viaGateway the money goes back through
     * Flutterwave; otherwise it was returned by hand and is only recorded. $endAccess ends the
     * plan now (the lifecycle then moves the shop on as for any ended plan).
     */
    public function refund(SubscriptionInvoice $invoice, ?float $amount, string $reason, User $actor, bool $viaGateway = false, bool $endAccess = false): SubscriptionInvoice
    {
        $reason = $this->reason($reason);
        if ($invoice->status !== 'paid') {
            throw BusinessRuleException::make('not_refundable', 'Only a paid invoice can be refunded.');
        }
        $amount = round($amount ?? (float) $invoice->amount, 2);
        if ($amount <= 0 || $amount > (float) $invoice->amount + 0.001) {
            throw BusinessRuleException::make('invalid_amount', 'Refund up to '.number_format((float) $invoice->amount).' '.$invoice->currency.'.');
        }
        $gateway = null;
        if ($viaGateway) {
            $tx = data_get($invoice->meta, 'flw_transaction_id');
            if (! $tx) {
                throw BusinessRuleException::make('no_transaction', 'This invoice was not paid through Flutterwave; record the refund without the gateway.');
            }
            $r = app(FlutterwaveService::class)->refund($tx, $amount < (float) $invoice->amount ? $amount : null);
            if (! $r['success']) {
                throw BusinessRuleException::make('gateway', 'Flutterwave refused the refund: '.($r['message'] ?? 'unknown reason'));
            }
            $gateway = $r['data'] ?? [];
        }

        return DB::transaction(function () use ($invoice, $amount, $reason, $actor, $gateway, $endAccess) {
            $invoice->refund_amount = $amount;
            $invoice->refunded_at = now();
            if ($amount >= (float) $invoice->amount - 0.001) {
                $invoice->status = 'refunded';
            }
            $invoice->meta = array_merge($invoice->meta ?? [], array_filter(['refund_gateway' => $gateway]));
            $invoice->save();
            $company = Company::withoutGlobalScopes()->findOrFail($invoice->company_id);
            $sub = $this->subscription($company);
            if ($endAccess && $sub->exists) {
                $sub->ends_at = now();
                $sub->auto_renew = false;
                $sub->save();
                $this->syncLicence($company, $sub);
            }
            BillingEvent::record((int) $invoice->company_id, 'refund', $actor->id, ['amount' => $amount, 'currency' => $invoice->currency, 'via_gateway' => $gateway !== null, 'end_access' => $endAccess],
                $reason, $sub->exists ? $sub->id : null, $invoice->id);

            return $invoice;
        });
    }
}

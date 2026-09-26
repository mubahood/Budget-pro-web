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
use App\Services\Notifications\Notifier;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Plan changes with proration, cancel/resume (plan C8, P3-6), and the capability of POWER_PLAN §4.2:
 * monthly or annual billing, mobile-money prompts without leaving the page, hosted checkout with the
 * caller's own return page, downgrades at the end of the period and card auto-renew.
 *
 * Unused days of a paid plan become credit toward the new plan; if the credit covers the new price the
 * change is immediate and the leftover becomes extra days. A platform-suspended shop cannot buy.
 */
class BillingService
{
    /** Free-text currencies that slipped in before CompanyRules restricted them (see the 2026_10_01 migration). */
    private const CURRENCY_ALIASES = ['UGSHS' => 'UGX', 'USHS' => 'UGX', 'USH' => 'UGX', 'SHS' => 'UGX', 'UGANDASHILLINGS' => 'UGX', 'KSH' => 'KES', 'KSHS' => 'KES', 'TSH' => 'TZS', 'FRW' => 'RWF'];

    public function currency(Company $company): string
    {
        $raw = strtoupper(str_replace([' ', '.'], '', (string) ($company->currency ?: config('saas.default_currency', 'UGX'))));
        $code = self::CURRENCY_ALIASES[$raw] ?? $raw;

        return in_array($code, (array) config('saas.currencies', []), true) ? $code : strtoupper((string) config('saas.default_currency', 'UGX'));
    }

    /** Where "Upgrade" / "Renew" links point: the new app's /plan when configured, else classic billing. */
    public static function billingUrl(): string
    {
        $url = (string) config('saas.billing_url');

        return $url !== '' ? $url : rtrim((string) config('saas.public_url', config('app.url')), '/').'/billing';
    }

    public static function interval(?string $interval): string
    {
        return $interval === 'year' ? 'year' : 'month';
    }

    private function assertCanBuy(Company $company): void
    {
        if ($company->isSuspended()) {
            throw BusinessRuleException::make('suspended', 'This shop is suspended. Please contact Budget Pro support — payments are not taken for a suspended shop.');
        }
    }

    /**
     * What buying $plan costs now, after credit for unused days of the current paid plan.
     *
     * @return array{amount: float, currency: string, credit: float, full_price: float, change: bool, immediate: bool, ends_at: ?string,
     *     starts_at: string, interval: string, per_month: float, per_day: float, saving: float, tax_rate: float, tax_amount: float,
     *     downgrade: bool, can_schedule: bool, payment_options: string}
     */
    public function quote(Company $company, Plan $plan, string $interval = 'month'): array
    {
        $this->assertCanBuy($company);
        $interval = self::interval($interval);
        if ($plan->isFree()) {
            throw BusinessRuleException::make('free_plan', 'The Free plan needs no payment. Cancel your plan to move to Free at the end of the period.');
        }
        $currency = $this->currency($company);
        ['amount' => $price, 'currency' => $chargeCurrency] = $plan->chargeIn($currency, $interval);
        if ($price <= 0) {
            throw BusinessRuleException::make('not_for_sale', 'This plan is not available for purchase.');
        }
        $sub = $company->subscription;
        $running = $sub !== null && $sub->isPaidAndRunning();
        $change = $running && ($sub->plan_id !== $plan->id || $sub->interval() !== $interval);
        $credit = $change ? $this->credit($sub, $chargeCurrency) : 0.0;
        $amount = round(max(0, $price - $credit), 2);
        $immediate = $change && $amount <= 0;
        $days = $plan->periodDays($interval);
        if ($immediate) {
            $starts = now();
            $ends = now()->addDays((int) floor($days * $credit / $price));
        } else {
            // A renewal stacks on the running period (also a trial's); a change starts a fresh one today.
            $starts = (! $change && $sub?->ends_at?->isFuture()) ? $sub->ends_at->copy() : now();
            $ends = $interval === 'year' || $plan->interval === 'year' ? $starts->copy()->addYear() : $starts->copy()->addMonth();
        }
        $monthly = $plan->monthlyPrice($chargeCurrency, $interval);
        $monthOnly = $plan->chargeIn($currency, 'month')['amount'];
        $current = $running ? $sub->plan->monthlyPrice($chargeCurrency, $sub->interval()) : 0.0;
        $rate = (float) config('saas.invoice.tax_rate', 0);

        return [
            'amount' => $amount, 'currency' => $chargeCurrency, 'credit' => round($credit, 2), 'full_price' => $price, 'change' => $change,
            'immediate' => $immediate, 'ends_at' => $ends->toIso8601String(), 'starts_at' => $starts->toIso8601String(), 'interval' => $interval,
            'per_month' => round($monthly, 2), 'per_day' => round($monthly * 12 / 365, 2),
            'saving' => $interval === 'year' ? round(max(0, $monthOnly * 12 - $price), 2) : 0.0,
            'tax_rate' => $rate, 'tax_amount' => $this->taxIn($amount, $rate),
            'downgrade' => $running && $sub->plan_id !== $plan->id && $monthly < $current,
            'can_schedule' => $running && $sub->status === 'active' && ($sub->plan_id !== $plan->id || $sub->interval() !== $interval),
            'payment_options' => $this->paymentOptions($chargeCurrency),
        ];
    }

    /** Tax contained in a tax-inclusive amount. */
    public function taxIn(float $amount, float $rate): float
    {
        return $rate > 0 ? round($amount * $rate / (100 + $rate), 2) : 0.0;
    }

    /**
     * Start paying for a plan: a change fully covered by credit applies at once, otherwise a pending
     * invoice + Flutterwave hosted checkout (mobile money/card). `$redirectUrl` is where Flutterwave
     * sends the browser back (the new app's /plan/return); default: budget-pro's /payment/callback.
     *
     * @return array{immediate: bool, quote: array, payment_link: ?string, tx_ref: ?string, invoice_id: ?int}
     */
    public function checkout(Company $company, User $user, Plan $plan, string $interval = 'month', ?string $redirectUrl = null, ?string $paymentOptions = null): array
    {
        $quote = $this->quote($company, $plan, $interval);
        if ($quote['immediate']) {
            $this->applyImmediateChange($company, $plan, $quote);

            return ['immediate' => true, 'quote' => $quote, 'payment_link' => null, 'tx_ref' => null, 'invoice_id' => null];
        }
        $invoice = $this->openInvoice($company, $plan, $quote, 'hosted');

        $result = app(FlutterwaveService::class)->initiatePayment([
            'tx_ref' => $invoice->provider_invoice_id, 'amount' => $quote['amount'], 'currency' => $quote['currency'],
            'redirect_url' => $redirectUrl ?: config('flutterwave.redirect_url'), 'payment_options' => $paymentOptions ?: $quote['payment_options'],
            'customer' => ['email' => $user->email ?: $company->email, 'name' => $user->name, 'phonenumber' => $user->phone_e164 ?: $user->phone_number],
            'customizations' => ['title' => config('app.name').' — '.$plan->name.' plan', 'description' => ($quote['interval'] === 'year' ? 'Yearly' : 'Monthly').' subscription'],
            'meta' => ['company_id' => $company->id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id],
        ]);
        if (! $result['success']) {
            $this->fail($invoice, (string) ($result['message'] ?? 'gateway'));
            throw BusinessRuleException::make('gateway', $result['message'] ?? 'Could not start payment.');
        }

        return ['immediate' => false, 'quote' => $quote, 'payment_link' => $result['link'], 'tx_ref' => $invoice->provider_invoice_id, 'invoice_id' => $invoice->id];
    }

    /**
     * Mobile-money prompt (MTN/Airtel…): the owner approves on their phone while the page polls
     * paymentStatus(). The invoice reference is the idempotency key: Flutterwave never charges one
     * tx_ref twice, and a repeat tap within two minutes re-uses the prompt already sent.
     *
     * @return array{immediate: bool, quote: array, invoice_id: ?int, tx_ref: ?string, status: string, redirect: ?string}
     */
    public function chargeMobileMoney(Company $company, User $user, Plan $plan, string $phone, ?string $network, string $interval = 'month'): array
    {
        $quote = $this->quote($company, $plan, $interval);
        if ($quote['immediate']) {
            $this->applyImmediateChange($company, $plan, $quote);

            return ['immediate' => true, 'quote' => $quote, 'invoice_id' => null, 'tx_ref' => null, 'status' => 'paid', 'redirect' => null];
        }
        $cur = $quote['currency'];
        $type = config("flutterwave.momo.charge_types.{$cur}");
        if (! $type) {
            throw BusinessRuleException::make('unsupported_currency', "Mobile money is not available for {$cur}. Pay by card instead.");
        }
        $e164 = Phone::e164($phone, $company->country ?: 'UG');
        if ($e164 === null) {
            throw BusinessRuleException::make('invalid_phone', 'Enter the mobile money number that will approve the payment.');
        }
        $networks = (array) config("flutterwave.momo.networks.{$cur}", []);
        $network = $network ? strtoupper($network) : null;
        if ($network !== null && $networks !== [] && ! array_key_exists($network, $networks)) {
            throw BusinessRuleException::make('invalid_network', 'Choose '.implode(' or ', array_keys($networks)).'.');
        }

        $recent = SubscriptionInvoice::where('company_id', $company->id)->where('status', 'pending')->where('created_at', '>=', now()->subMinutes(2))
            ->orderByDesc('id')->get()->first(fn (SubscriptionInvoice $i) => data_get($i->meta, 'method') === 'momo' && data_get($i->meta, 'phone') === $e164
                && (int) data_get($i->meta, 'plan_id') === $plan->id && $i->interval() === $quote['interval'] && abs((float) $i->amount - $quote['amount']) < 0.01);
        if ($recent !== null) {
            return ['immediate' => false, 'quote' => $quote, 'invoice_id' => $recent->id, 'tx_ref' => $recent->provider_invoice_id, 'status' => 'pending', 'redirect' => data_get($recent->meta, 'redirect')];
        }

        $invoice = $this->openInvoice($company, $plan, $quote, 'momo', ['phone' => $e164, 'network' => $network]);
        $r = app(FlutterwaveService::class)->chargeMobileMoney($type, array_filter([
            'tx_ref' => $invoice->provider_invoice_id, 'amount' => $quote['amount'], 'currency' => $cur, 'phone_number' => ltrim($e164, '+'),
            'network' => $network !== null ? ($networks[$network] ?? $network) : null,
            'email' => $user->email ?: ($company->email ?: 'shop'.$company->id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'budgetpro.app')),
            'fullname' => $user->name ?: $company->name, 'meta' => ['company_id' => $company->id, 'plan_id' => $plan->id, 'invoice_id' => $invoice->id],
        ], fn ($v) => $v !== null));
        if (! $r['success']) {
            $this->fail($invoice, (string) ($r['message'] ?? 'momo'));
            throw BusinessRuleException::make('gateway', $r['message'] ?? 'The mobile money request failed. Try again or pay by card.');
        }
        if (! empty($r['redirect'])) {
            $invoice->meta = array_merge($invoice->meta ?? [], ['redirect' => $r['redirect']]);
            $invoice->save();
        }

        return ['immediate' => false, 'quote' => $quote, 'invoice_id' => $invoice->id, 'tx_ref' => $invoice->provider_invoice_id, 'status' => 'pending', 'redirect' => $r['redirect'] ?? null];
    }

    /**
     * Where a payment stands, asking Flutterwave while it is pending (the page polls this).
     *
     * @return array{status: string, invoice: SubscriptionInvoice, message: string}
     */
    public function paymentStatus(Company $company, int $invoiceId): array
    {
        $invoice = SubscriptionInvoice::where('company_id', $company->id)->findOrFail($invoiceId);
        if ($invoice->status === 'pending') {
            $r = app(SubscriptionFulfillment::class)->verifyAndFulfill((string) $invoice->provider_invoice_id);

            return ['status' => $r['status'] === 'paid' || $r['status'] === 'failed' ? $r['status'] : 'pending', 'invoice' => $invoice->fresh(), 'message' => $r['message']];
        }

        return ['status' => $invoice->status, 'invoice' => $invoice, 'message' => ''];
    }

    /** @return array<string, string> network key => label, for the mobile-money picker */
    public function momoNetworks(Company $company): array
    {
        $keys = array_keys((array) config('flutterwave.momo.networks.'.$this->currency($company), []));

        return array_combine($keys, array_map(fn ($k) => ['MTN' => 'MTN MoMo', 'AIRTEL' => 'Airtel Money', 'MPESA' => 'M-Pesa'][$k] ?? ucfirst(strtolower($k)), $keys)) ?: [];
    }

    public function paymentOptions(string $currency): string
    {
        return match ($currency) {
            'UGX' => (string) config('flutterwave.payment_options'),
            default => (string) (config("flutterwave.local_payment_options.{$currency}") ?? config('flutterwave.international_payment_options')),
        };
    }

    /** A pending invoice whose reference is the payment's idempotency key. */
    private function openInvoice(Company $company, Plan $plan, array $quote, string $method, array $meta = []): SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::create([
            'company_id' => $company->id, 'amount' => $quote['amount'], 'currency' => $quote['currency'], 'status' => 'pending', 'provider' => 'flutterwave',
            'tax_rate' => $quote['tax_rate'], 'tax_amount' => $quote['tax_amount'],
            'meta' => ['plan_id' => $plan->id, 'interval' => $quote['interval'], 'method' => $method, 'is_uganda' => $quote['currency'] === 'UGX', 'change' => $quote['change'],
                'credit' => $quote['credit'], 'full_price' => $quote['full_price']] + $meta,
        ]);
        $invoice->provider_invoice_id = 'BPRO-'.$invoice->id.'-'.Str::upper(Str::random(10));
        $invoice->save();

        return $invoice;
    }

    private function fail(SubscriptionInvoice $invoice, string $why): void
    {
        $invoice->status = 'failed';
        $invoice->meta = array_merge($invoice->meta ?? [], ['failure' => mb_substr($why, 0, 200)]);
        $invoice->save();
    }

    /** Value of the unused days of the current plan, in the charge currency. */
    private function credit(Subscription $sub, string $currency): float
    {
        $plan = $sub->plan;
        ['amount' => $paid, 'currency' => $cur] = $plan->chargeIn($currency, $sub->interval());
        if ($cur !== $currency || $paid <= 0) {
            return 0.0;
        }
        $remaining = now()->diffInSeconds($sub->ends_at) / 86400;

        return max(0.0, $paid * min(1.0, $remaining / $plan->periodDays($sub->interval())));
    }

    /** A change fully paid by credit: switch now, no payment. */
    public function applyImmediateChange(Company $company, Plan $plan, array $quote): Subscription
    {
        $sub = $company->subscription;
        $sub->plan_id = $plan->id;
        $sub->billing_interval = self::interval($quote['interval'] ?? 'month');
        $sub->pending_plan_id = null;
        $sub->pending_change_at = null;
        $sub->status = 'active';
        $sub->canceled_at = null;
        $sub->ends_at = Carbon::parse($quote['ends_at']);
        $sub->save();
        $company->license_expire = $sub->ends_at;
        $company->saveQuietly();
        $company->unsetRelation('subscription');
        BillingEvent::record((int) $company->id, 'plan_changed', auth()->id(), ['plan_id' => $plan->id, 'credit' => $quote['credit'], 'ends_at' => $sub->ends_at->toIso8601String()], null, $sub->id);
        app(Notifier::class)->notify((int) $company->id, 'billing', 'Plan changed', "You are now on {$plan->name}, paid by your unused days until ".$sub->ends_at->toFormattedDateString().'.');

        return $sub;
    }

    /**
     * "At the end of my period": the shop keeps its plan until the paid period ends, then Lifecycle
     * moves it to $plan (which is then renewed at its own price).
     */
    public function scheduleChange(Company $company, Plan $plan, string $interval = 'month'): Subscription
    {
        $this->assertCanBuy($company);
        $sub = $company->subscription;
        $interval = self::interval($interval);
        if ($sub === null || ! $sub->isPaidAndRunning() || $sub->status !== 'active') {
            throw BusinessRuleException::make('nothing_to_change', 'There is no running paid plan to change at the end of its period.');
        }
        if ($plan->isFree()) {
            throw BusinessRuleException::make('free_plan', 'To move to the Free plan, cancel your plan: it stays on until the end of the period.');
        }
        if ($sub->plan_id === $plan->id && $sub->interval() === $interval) {
            throw BusinessRuleException::make('same_plan', "You are already on {$plan->name}.");
        }
        $sub->pending_plan_id = $plan->id;
        $sub->pending_change_at = $sub->ends_at;
        $sub->meta = array_merge($sub->meta ?? [], ['pending_interval' => $interval]);
        $sub->save();
        BillingEvent::record((int) $company->id, 'change_scheduled', auth()->id(), ['plan_id' => $plan->id, 'interval' => $interval, 'at' => $sub->ends_at->toIso8601String()], null, $sub->id);

        return $sub;
    }

    public function cancelScheduledChange(Company $company): Subscription
    {
        $sub = $company->subscription;
        if ($sub === null || $sub->pending_plan_id === null) {
            throw BusinessRuleException::make('nothing_scheduled', 'No plan change is scheduled.');
        }
        $sub->pending_plan_id = null;
        $sub->pending_change_at = null;
        $sub->save();

        return $sub;
    }

    /** Cancel at the end of the paid period; the shop then moves to the Free plan. */
    public function cancel(Company $company): Subscription
    {
        $sub = $company->subscription;
        if ($sub === null || ! $sub->isPaidAndRunning() || $sub->status === 'canceled') {
            throw BusinessRuleException::make('nothing_to_cancel', 'There is no paid plan to cancel.');
        }
        $sub->status = 'canceled';
        $sub->canceled_at = now();
        $sub->auto_renew = false;
        $sub->pending_plan_id = null;
        $sub->pending_change_at = null;
        $sub->save();
        BillingEvent::record((int) $company->id, 'canceled', auth()->id(), ['ends_at' => $sub->ends_at?->toIso8601String()], null, $sub->id);

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
        BillingEvent::record((int) $company->id, 'resumed', auth()->id(), [], null, $sub->id);

        return $sub;
    }

    /** Card auto-renew on/off (needs a saved card and the platform switch saas.auto_renew). */
    public function setAutoRenew(Company $company, bool $on): Subscription
    {
        $sub = $company->subscription;
        if ($on && (! config('saas.auto_renew') || $sub?->savedCard() === null)) {
            throw BusinessRuleException::make('auto_renew_unavailable', 'Automatic renewal needs a card payment first.');
        }
        if ($sub === null) {
            throw BusinessRuleException::make('nothing_to_change', 'There is no plan to renew.');
        }
        $sub->auto_renew = $on;
        $sub->save();

        return $sub;
    }

    /**
     * Auto-renew: charge the saved card for the next period (the pending plan if a change is scheduled).
     * Verified with Flutterwave and fulfilled like any other payment; on failure the caller falls back
     * to reminders.
     *
     * @return array{status: string, message: string, invoice_id: ?int}
     */
    public function chargeSavedCard(Company $company): array
    {
        $sub = $company->subscription;
        $card = $sub?->savedCard();
        if ($sub === null || $card === null || $company->isSuspended()) {
            return ['status' => 'skipped', 'message' => 'No saved card.', 'invoice_id' => null];
        }
        $plan = $sub->pendingPlan ?? $sub->plan;
        $interval = $sub->pending_plan_id ? self::interval(data_get($sub->meta, 'pending_interval')) : $sub->interval();
        ['amount' => $amount, 'currency' => $cur] = $plan->chargeIn($this->currency($company), $interval);
        $rate = (float) config('saas.invoice.tax_rate', 0);
        $invoice = $this->openInvoice($company, $plan, ['amount' => $amount, 'currency' => $cur, 'interval' => $interval, 'change' => false, 'credit' => 0.0,
            'full_price' => $amount, 'tax_rate' => $rate, 'tax_amount' => $this->taxIn($amount, $rate)], 'card_token', ['auto_renew' => true]);
        $owner = User::withoutGlobalScopes()->find($company->owner_id);
        $r = app(FlutterwaveService::class)->chargeToken([
            'token' => $card['token'], 'currency' => $cur, 'amount' => $amount, 'tx_ref' => $invoice->provider_invoice_id,
            'email' => $card['email'] ?? ($owner?->email ?: $company->email), 'narration' => config('app.name').' '.$plan->name.' renewal',
        ]);
        if (! $r['success']) {
            $this->fail($invoice, (string) ($r['message'] ?? 'card'));

            return ['status' => 'failed', 'message' => (string) ($r['message'] ?? 'The card was declined.'), 'invoice_id' => $invoice->id];
        }
        $v = app(SubscriptionFulfillment::class)->verifyAndFulfill($invoice->provider_invoice_id, $r['data']['id'] ?? null);
        if ($v['status'] === 'failed') {
            return ['status' => 'failed', 'message' => $v['message'], 'invoice_id' => $invoice->id];
        }

        return ['status' => $v['status'], 'message' => $v['message'], 'invoice_id' => $invoice->id];
    }
}

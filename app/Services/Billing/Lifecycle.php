<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Notifications\Notices;
use App\Services\Notifications\Notifier;
use App\Services\Sync\SyncApplier;

/**
 * Subscription lifecycle (plan C8, decision H2; POWER_PLAN §4.2), idempotent and run hourly:
 * trial reminders (3 days, 1 day) → trial ends → Free plan;
 * paid plans: renewal reminders 7/3/1 days before the end (or a saved-card charge 2 days before, when
 * auto-renew is on, falling back to reminders if it fails);
 * a downgrade scheduled for the end of the period is applied when the period ends;
 * paid period ends → past_due + dunning → after the grace days → Free plan;
 * cancelled plans move to Free when their period ends;
 * a shop past its monthly sales allowance (a soft limit) is told once a month.
 */
class Lifecycle
{
    public function __construct(private readonly Notifier $notifier = new Notifier())
    {
    }

    /**
     * The plan a lapsed account falls back to (ADR 0005), on the account's own plan line (PlanLine, AK-15):
     * `free` for the family's apps, `akiba-free` for a SACCO, `estate-ledger-free` for an Estate Ledger account. Without a
     * company: the family's.
     */
    public function freePlan(?Company $company = null): ?Plan
    {
        if (! config('saas.free_tier_fallback', true)) {
            return null;
        }
        $slug = PlanLine::freeSlug($company !== null ? PlanLine::for($company) : PlanLine::FAMILY);

        return Plan::where('slug', $slug)->where('is_active', true)->first();
    }

    /** @return array<string, int> counts per transition */
    public function run(): array
    {
        $counts = ['trial_reminders' => 0, 'to_free' => 0, 'past_due' => 0, 'dunning' => 0, 'renewal_reminders' => 0, 'auto_renew' => 0, 'auto_renew_failed' => 0,
            'changes_applied' => 0, 'limit_notices' => 0];
        $grace = (int) config('saas.grace_days', 7);

        Subscription::query()->whereIn('status', ['trialing', 'active', 'past_due', 'canceled', 'expired'])->chunkById(200, function ($subs) use (&$counts, $grace) {
            foreach ($subs as $sub) {
                $company = Company::withoutGlobalScopes()->find($sub->company_id);
                if ($company === null || $company->subscription?->id !== $sub->id) {
                    continue; // only the current subscription matters
                }
                $this->step($company, $sub, $grace, $counts);
            }
        });

        return $counts;
    }

    private function step(Company $company, Subscription $sub, int $grace, array &$counts): void
    {
        $cid = (int) $company->id;
        $free = $sub->plan?->isFree() ?? false;
        if ($company->hasActiveAccess()) {
            $this->softLimits($company, $counts);
        }

        if ($sub->status === 'trialing') {
            $end = $sub->trial_ends_at ?? $sub->ends_at;
            if ($end === null) {
                return;
            }
            foreach ([3, 1] as $d) {
                if ($end->isFuture() && now()->diffInHours($end) <= $d * 24) {
                    $counts['trial_reminders'] += (int) Notices::once($cid, 'trial_ending', "d{$d}:".$end->toDateString(), fn () => $this->notifier->notify($cid, 'billing',
                        "Your free trial ends in {$d} day".($d > 1 ? 's' : ''), 'It ends on '.self::day($end).'. Choose a plan to keep everything as it is: '.BillingService::billingUrl()
                    .'. If you don\'t, '.$company->name.' moves to the Free plan ('.$this->freeLimits($company).'). Nothing is deleted.', ['url' => BillingService::billingUrl()]));
                }
            }
            if ($end->isPast()) {
                $counts['to_free'] += (int) $this->toFree($company, $sub, 'Your trial has ended');
            }

            return;
        }
        if ($free || $sub->ends_at === null) {
            return;
        }
        if ($sub->ends_at->isFuture()) {
            if ($sub->status === 'active') {
                $this->beforeRenewal($company, $sub, $counts);
            }

            return;
        }
        if ($sub->pending_plan_id !== null && in_array($sub->status, ['active', 'past_due'], true)) {
            $this->applyScheduledChange($company, $sub, $counts);
            $free = $sub->plan?->isFree() ?? false;
        }
        if ($sub->status === 'canceled') {
            $counts['to_free'] += (int) $this->toFree($company, $sub, 'Your plan has ended');

            return;
        }
        if ($sub->status === 'active') {
            $sub->status = 'past_due';
            $sub->save();
            $counts['past_due']++;
        }
        $daysOver = (int) floor($sub->ends_at->diffInHours(now()) / 24);
        if ($daysOver >= $grace) {
            $counts['to_free'] += (int) $this->toFree($company, $sub, 'Your plan has ended');

            return; // past the grace window: no catch-up reminders, straight to Free
        }
        // Only the latest reminder that is due (day 0, 3, last grace day), once each. Nothing stops in these days: the words say
        // so, say when the Free plan starts and what it allows, and how to keep the plan. Never "payment due" for a plan given free.
        $due = collect([0, 3, $grace - 1])->filter(fn ($d) => $d < $grace && $daysOver >= $d)->max();
        if ($due !== null) {
            $left = $grace - $daysOver;
            $name = $sub->plan?->name ?? 'your';
            $until = self::day($sub->ends_at->copy()->addDays($grace));
            $counts['dunning'] += (int) Notices::once($cid, 'dunning', "d{$due}:".$sub->ends_at->toDateString(), fn () => $this->notifier->notify($cid, 'billing',
                $left <= 1 ? 'Tomorrow you move to the Free plan' : 'Your '.$name.' plan has ended',
                'Everything keeps working until '.$until.'. To keep '.$name.', pay '.$this->priceText($company, $sub->plan, $sub->interval()).': '.BillingService::billingUrl()
                .'. If you don\'t, on '.$until.' '.$company->name.' moves to the Free plan ('.$this->freeLimits($company).'). Nothing is deleted.',
                ['url' => BillingService::billingUrl()]));
        }
    }

    /** 8 Nov 2026: how a date reads in a message. */
    private static function day(\DateTimeInterface $at): string
    {
        return $at->format('j M Y');
    }

    /** "UGX 1,000,000 for a year, or UGX 100,000 a month": what keeping the plan costs, with the smaller payment beside a year's. */
    private function priceText(Company $company, ?Plan $plan, string $interval): string
    {
        if ($plan === null) {
            return 'for your plan';
        }
        $currency = app(BillingService::class)->currency($company);
        $say = fn (array $p) => $p['currency'].' '.number_format($p['amount']);
        $month = $plan->chargeIn($currency, 'month');
        if ($interval === 'year') {
            return $say($plan->chargeIn($currency, 'year')).' for a year, or '.$say($month).' a month';
        }

        return $say($month).' a month';
    }

    /** What the Free plan of this account's app allows, in words. */
    private function freeLimits(Company $company): string
    {
        $free = $this->freePlan($company);

        return $free === null ? 'with lower limits' : $this->limitsText($free);
    }

    /** Renewal reminders 7/3/1 days before a paid period ends (only the latest due, once each), or the saved-card charge. */
    private function beforeRenewal(Company $company, Subscription $sub, array &$counts): void
    {
        $cid = (int) $company->id;
        $hoursLeft = now()->diffInHours($sub->ends_at, false);
        $end = $sub->ends_at->toDateString();
        $next = $sub->pendingPlan ?? $sub->plan;
        $interval = $sub->pending_plan_id ? BillingService::interval(data_get($sub->meta, 'pending_interval')) : $sub->interval();
        $billing = app(BillingService::class);

        if (config('saas.auto_renew') && $sub->auto_renew && $sub->savedCard() !== null && $hoursLeft <= (int) config('saas.auto_renew_hours_before', 48)) {
            // One attempt per period; a failure falls back to the reminders below.
            $attempt = null;
            Notices::once($cid, 'auto_renew', $end, function () use ($billing, $company, &$attempt) {
                $attempt = $billing->chargeSavedCard($company);
            });
            if ($attempt !== null) {
                if ($attempt['status'] === 'paid') {
                    $counts['auto_renew']++;

                    return;
                }
                $counts['auto_renew_failed']++;
                $card = $sub->savedCard();
                $this->notifier->notify($cid, 'billing', 'We could not renew with your card', 'The card ending '.($card['last4'] ?? '····').' was not charged ('.$attempt['message'].'). Renew before '
                    .$sub->ends_at->toFormattedDateString().' to keep '.($next?->name ?? 'your plan').': '.BillingService::billingUrl(), ['url' => BillingService::billingUrl()]);
            }
        }

        $due = collect((array) config('saas.renewal_reminder_days', [7, 3, 1]))->filter(fn ($d) => $hoursLeft <= $d * 24)->min();
        if ($due === null || $hoursLeft <= 0) {
            return;
        }
        $price = $next ? $next->chargeIn($billing->currency($company), $interval) : null;
        $auto = config('saas.auto_renew') && $sub->auto_renew && $sub->savedCard() !== null;
        $name = $next?->name ?? 'your';
        $in = $due.' day'.($due > 1 ? 's' : '');
        $grace = (int) config('saas.grace_days', 7);
        // "Renews" only when it really will by itself; a plan given free says so, and nobody is told a payment is due before it is.
        $title = $auto ? $name.' renews in '.$in : ($sub->provider === 'comp' ? 'Your free '.$name.' plan ends in '.$in : 'Your '.$name.' plan ends in '.$in);
        $body = $auto
            ? 'We will charge your saved card'.($price ? ' '.$price['currency'].' '.number_format($price['amount']) : '').' on '.self::day($sub->ends_at).'.'
            : 'It ends on '.self::day($sub->ends_at).'. To keep '.$name.', pay '.$this->priceText($company, $next, $interval).': '.BillingService::billingUrl()
                .'. If you don\'t, nothing stops: everything keeps working until '.self::day($sub->ends_at->copy()->addDays($grace)).', then '.$company->name
                .' moves to the Free plan. Nothing is deleted.';
        $counts['renewal_reminders'] += (int) Notices::once($cid, 'renewal', "d{$due}:{$end}", fn () => $this->notifier->notify($cid, 'billing', $title, $body,
            ['url' => BillingService::billingUrl()]));
    }

    /** The period has ended: a change the owner scheduled "for the end of my period" takes effect. */
    private function applyScheduledChange(Company $company, Subscription $sub, array &$counts): void
    {
        $plan = $sub->pendingPlan;
        $sub->pending_plan_id = null;
        $sub->pending_change_at = null;
        if ($plan !== null && $plan->is_active) {
            $sub->plan_id = $plan->id;
            $sub->billing_interval = BillingService::interval(data_get($sub->meta, 'pending_interval'));
            $sub->setRelation('plan', $plan);
            $counts['changes_applied']++;
            \App\Models\BillingEvent::record((int) $company->id, 'change_applied', null, ['plan_id' => $plan->id, 'interval' => $sub->billing_interval], null, $sub->id);
            $price = $plan->chargeIn(app(BillingService::class)->currency($company), $sub->interval());
            $this->notifier->notify((int) $company->id, 'billing', "You are now on {$plan->name}", 'Your previous plan ended and '.$plan->name.' is now your plan. Pay '
                .number_format($price['amount']).' '.$price['currency'].' to keep it running: '.BillingService::billingUrl(), ['url' => BillingService::billingUrl()]);
        }
        $sub->save();
        $company->unsetRelation('subscription');
    }

    /** Soft limits (sales per month): never refused, the owner is told once a month. */
    private function softLimits(Company $company, array &$counts): void
    {
        $quotas = new Quotas();
        foreach (Quotas::SOFT as $what) {
            $limit = $quotas->limit($company, $what);
            if ($limit === null || ! $quotas->over($company, $what)) {
                continue;
            }
            $cid = (int) $company->id;
            $counts['limit_notices'] += (int) Notices::once($cid, 'limit:'.$what, now()->format('Y-m'), fn () => $this->notifier->notify($cid, 'billing',
                'You have passed your plan\'s '.number_format($limit).' '.Quotas::LABELS[$what],
                'Keep selling — nothing is blocked. To stay within your plan next month, upgrade: '.BillingService::billingUrl(), ['url' => BillingService::billingUrl()]));
        }
    }

    private function toFree(Company $company, Subscription $sub, string $why): bool
    {
        $free = $this->freePlan($company);
        if ($free === null) {
            if ($sub->status !== 'expired') {
                $sub->status = 'expired';
                $sub->save();
            }

            return false;
        }
        $sub->plan_id = $free->id;
        $sub->status = 'active';
        $sub->trial_ends_at = null;
        $sub->canceled_at = null;
        $sub->ends_at = null; // Free never ends
        $sub->provider = 'free';
        $sub->billing_interval = 'month';
        $sub->pending_plan_id = null;
        $sub->pending_change_at = null;
        $sub->auto_renew = false;
        $sub->save();
        $company->license_expire = null;
        $company->saveQuietly();
        $company->unsetRelation('subscription');
        app(SyncApplier::class)->applyHeld($company);
        $on = PlanLine::of($free) === PlanLine::FAMILY ? 'the Free plan' : $free->name;
        $over = $this->overLimits($company);
        $this->notifier->notify((int) $company->id, 'billing', $why, "{$company->name} is now on {$on}: ".$this->limitsText($free).'. Nothing was deleted, and what you already have keeps working.'
            .($over === '' ? '' : ' You have '.$over.': adding more needs a paid plan.').' Upgrade any time: '.BillingService::billingUrl(), ['url' => BillingService::billingUrl()]);

        return true;
    }

    /** "467 products and 2 phones": what the account already has beyond its plan's hard limits, or ''. */
    private function overLimits(Company $company): string
    {
        $quotas = new Quotas();
        $over = [];
        foreach (['products', 'devices', 'users', 'members', 'units', 'people', 'locations'] as $what) {
            $limit = $quotas->limit($company, $what);
            $used = $limit === null ? 0 : $quotas->used($company, $what);
            if ($limit !== null && $used > $limit) {
                $over[] = number_format($used).' '.($used === 1 ? rtrim(Quotas::LABELS[$what], 's') : Quotas::LABELS[$what]);
            }
        }
        $last = array_pop($over);

        return $over === [] ? (string) $last : implode(', ', $over).' and '.$last;
    }

    private function limitsText(Plan $plan): string
    {
        $l = $plan->limits ?? [];

        return implode(', ', array_filter([
            isset($l['max_members']) ? $l['max_members'].' members' : null,
            isset($l['max_units']) ? $l['max_units'].' units' : null,
            isset($l['max_people']) ? number_format((int) $l['max_people']).' people' : null,
            isset($l['max_devices']) ? $l['max_devices'].' phone'.($l['max_devices'] > 1 ? 's' : '') : null,
            isset($l['max_products']) ? $l['max_products'].' products' : null,
            isset($l['max_sales_per_month']) ? $l['max_sales_per_month'].' sales a month' : null,
        ]));
    }
}

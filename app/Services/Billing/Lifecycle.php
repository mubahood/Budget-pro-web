<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Notifications\Notices;
use App\Services\Notifications\Notifier;
use App\Services\Sync\SyncApplier;

/**
 * Subscription lifecycle (plan C8, decision H2), idempotent and run hourly:
 * trial reminders (3 days, 1 day) → trial ends → Free plan;
 * paid period ends → past_due + dunning → after the grace days → Free plan;
 * cancelled plans move to Free when their period ends.
 */
class Lifecycle
{
    public function __construct(private readonly Notifier $notifier = new Notifier())
    {
    }

    public function freePlan(): ?Plan
    {
        return config('saas.free_tier_fallback', true) ? Plan::where('slug', config('saas.free_plan', 'free'))->where('is_active', true)->first() : null;
    }

    /** @return array<string, int> counts per transition */
    public function run(): array
    {
        $counts = ['trial_reminders' => 0, 'to_free' => 0, 'past_due' => 0, 'dunning' => 0];
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

        if ($sub->status === 'trialing') {
            $end = $sub->trial_ends_at ?? $sub->ends_at;
            if ($end === null) {
                return;
            }
            foreach ([3, 1] as $d) {
                if ($end->isFuture() && now()->diffInHours($end) <= $d * 24) {
                    $counts['trial_reminders'] += (int) Notices::once($cid, 'trial_ending', "d{$d}:".$end->toDateString(), fn () => $this->notifier->notify($cid, 'billing',
                        "Your free trial ends in {$d} day".($d > 1 ? 's' : ''), 'Choose a plan to keep every feature. If you do nothing you move to the Free plan — your data stays safe.'));
                }
            }
            if ($end->isPast()) {
                $counts['to_free'] += (int) $this->toFree($company, $sub, 'Your trial has ended');
            }

            return;
        }
        if ($free || $sub->ends_at === null || $sub->ends_at->isFuture()) {
            return;
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
        // Only the latest reminder that is due (day 0, 3, last grace day), once each.
        $due = collect([0, 3, $grace - 1])->filter(fn ($d) => $d < $grace && $daysOver >= $d)->max();
        if ($due !== null) {
            $left = $grace - $daysOver;
            $counts['dunning'] += (int) Notices::once($cid, 'dunning', "d{$due}:".$sub->ends_at->toDateString(), fn () => $this->notifier->notify($cid, 'billing',
                'Payment due for '.($sub->plan?->name ?? 'your plan'), "Your plan ended. Renew within {$left} day".($left > 1 ? 's' : '').' to keep your plan; after that the shop moves to the Free plan.'));
        }
    }

    private function toFree(Company $company, Subscription $sub, string $why): bool
    {
        $free = $this->freePlan();
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
        $sub->save();
        $company->license_expire = null;
        $company->saveQuietly();
        $company->unsetRelation('subscription');
        app(SyncApplier::class)->applyHeld($company);
        $this->notifier->notify((int) $company->id, 'billing', $why, "{$company->name} is now on the Free plan: ".$this->limitsText($free).'. Upgrade any time from Billing.');

        return true;
    }

    private function limitsText(Plan $plan): string
    {
        $l = $plan->limits ?? [];

        return implode(', ', array_filter([
            isset($l['max_devices']) ? $l['max_devices'].' phone'.($l['max_devices'] > 1 ? 's' : '') : null,
            isset($l['max_products']) ? $l['max_products'].' products' : null,
            isset($l['max_sales_per_month']) ? $l['max_sales_per_month'].' sales a month' : null,
        ]));
    }
}

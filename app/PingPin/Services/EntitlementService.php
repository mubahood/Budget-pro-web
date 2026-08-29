<?php

namespace App\PingPin\Services;

use App\Models\Company;
use App\Models\PingPinPlan;

/**
 * The one call site that answers "can this organisation do X right now"
 * (PLAN.md §8) — the direct fix for the audit's single most concrete
 * finding: budget-pro's own plans have carried features/limits JSON since
 * mid-August with ZERO enforcement call sites anywhere in the codebase.
 * Every Ping Pin endpoint that's plan-gated calls this, never trusting a
 * client-sent flag or re-deriving entitlement logic locally.
 */
class EntitlementService
{
    /**
     * The plan behind the company's currently-active Ping Pin subscription,
     * or null if it has none (never signed up for Ping Pin) or it's lapsed.
     * Null here means "no access to anything Ping Pin gates" — never
     * "treat as unlimited."
     */
    public function activePlan(Company $company): ?PingPinPlan
    {
        $subscription = $company->pingPinSubscription;
        if ($subscription === null || ! $subscription->isActive()) {
            return null;
        }

        return $subscription->plan;
    }

    public function allows(Company $company, string $feature): bool
    {
        $plan = $this->activePlan($company);

        return $plan !== null && $plan->allowsFeature($feature);
    }

    /**
     * Null means unlimited (an active plan explicitly has no cap for this
     * key) OR "not applicable" is indistinguishable here from "unlimited" —
     * callers that need to tell "no active plan" apart from "unlimited"
     * should call activePlan() themselves first, same as
     * hasReachedLimit()/remainingQuota() below do.
     */
    public function limitFor(Company $company, string $key): ?int
    {
        return $this->activePlan($company)?->limit($key);
    }

    /**
     * True if there's no active plan at all (blocked, not "unlimited") OR
     * the plan has a finite limit that current usage has met or exceeded.
     */
    public function hasReachedLimit(Company $company, string $limitKey, int $currentUsage): bool
    {
        $plan = $this->activePlan($company);
        if ($plan === null) {
            return true;
        }

        $limit = $plan->limit($limitKey);
        if ($limit === null) {
            return false; // explicitly unlimited on this plan
        }

        return $currentUsage >= $limit;
    }

    /** Null = no active plan (blocked) or unlimited — same ambiguity as limitFor(), same reasoning. */
    public function remainingQuota(Company $company, string $limitKey, int $currentUsage): ?int
    {
        $plan = $this->activePlan($company);
        if ($plan === null) {
            return 0;
        }

        $limit = $plan->limit($limitKey);
        if ($limit === null) {
            return null; // unlimited
        }

        return max(0, $limit - $currentUsage);
    }
}

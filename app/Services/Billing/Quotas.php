<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Plan limits (plan A10, P3-7; POWER_PLAN §4.1). Online writes are refused with 422
 * `plan_limit_reached`; offline pushes are accepted and flagged (a cashier is never blocked from
 * finishing a sale), and `usage()` rides in auth/me.
 *
 * Sales per month is a soft limit: a sale is never refused. Past the limit the owner is told once a
 * month (Lifecycle) and the plan page shows it. Storage is checked when a file is uploaded.
 */
class Quotas
{
    public const KEYS = ['users' => 'max_users', 'products' => 'max_products', 'sales' => 'max_sales_per_month', 'storage' => 'storage_mb', 'devices' => 'max_devices', 'locations' => 'max_locations'];

    /** Limits that only warn (never refuse). */
    public const SOFT = ['sales'];

    public const LABELS = ['users' => 'team members', 'products' => 'products', 'sales' => 'sales this month', 'storage' => 'MB of files', 'devices' => 'phones', 'locations' => 'locations'];

    public function limits(Company $company): array
    {
        return array_merge(Entitlements::DEFAULT_LIMITS, $company->subscription?->plan?->limits ?? []);
    }

    public function used(Company $company, string $what): int
    {
        return match ($what) {
            'users' => (int) DB::table('admin_users')->where('company_id', $company->id)->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Inactive'))->count()
                + (int) DB::table('invites')->where('company_id', $company->id)->where('status', 'pending')->where('expires_at', '>', now())->count(),
            'products' => (int) DB::table('stock_items')->where('company_id', $company->id)->where('is_deleted', false)->count(),
            'sales' => (int) DB::table('sale_records')->where('company_id', $company->id)->where('created_at', '>=', now()->startOfMonth())->count(),
            'storage' => (int) ceil($this->storageBytes($company) / 1048576),
            'devices' => (int) DB::table('devices')->where('company_id', $company->id)->where('status', 'active')->whereNull('revoked_at')->count(),
            'locations' => max(1, (int) DB::table('locations')->where('company_id', $company->id)->count()),
            default => 0,
        };
    }

    public function storageBytes(Company $company): int
    {
        return (int) DB::table('files')->where('company_id', $company->id)->sum('size_bytes');
    }

    public function limit(Company $company, string $what): ?int
    {
        $v = $this->limits($company)[self::KEYS[$what]] ?? null;

        return $v === null || $v === '' ? null : (int) $v;
    }

    public function allows(Company $company, string $what, int $adding = 1): bool
    {
        $limit = $this->limit($company, $what);

        return $limit === null || $this->used($company, $what) + $adding <= $limit;
    }

    public function assertCanAdd(Company $company, string $what, int $adding = 1): void
    {
        if (in_array($what, self::SOFT, true)) {
            return; // never refuse a sale (E34)
        }
        if (! $this->allows($company, $what, $adding)) {
            $limit = $this->limit($company, $what);
            throw $this->limitReached($company, $what, $limit, $this->used($company, $what), "Your plan allows {$limit} ".self::LABELS[$what].'. Upgrade to add more.');
        }
    }

    /** Refuse an upload that would take the shop past its storage (MB) allowance. */
    public function assertCanStore(Company $company, int $bytes): void
    {
        $limit = $this->limit($company, 'storage');
        if ($limit === null) {
            return;
        }
        $used = $this->storageBytes($company);
        if ($used + $bytes > $limit * 1048576) {
            $usedMb = (int) ceil($used / 1048576);
            throw $this->limitReached($company, 'storage', $limit, $usedMb, "Your plan includes {$limit} MB of files and {$usedMb} MB are used. Upgrade for more space, or delete old files.");
        }
    }

    private function limitReached(Company $company, string $what, ?int $limit, int $used, string $message): BusinessRuleException
    {
        $next = $this->planThatFits($company, $what, $used + 1);

        return BusinessRuleException::make('plan_limit_reached', $message, ['limit' => self::KEYS[$what], 'max' => $limit, 'used' => $used,
            'upgrade_url' => BillingService::billingUrl(), 'next_plan' => $next?->slug]);
    }

    /** Past a soft limit (sales this month over the plan's number)? */
    public function over(Company $company, string $what): bool
    {
        $limit = $this->limit($company, $what);

        return $limit !== null && $this->used($company, $what) > $limit;
    }

    /**
     * The cheapest public paid plan, dearer than the current one, whose limit for $what fits $needed.
     */
    public function planThatFits(Company $company, string $what, int $needed): ?Plan
    {
        $key = self::KEYS[$what] ?? $what;
        $current = $company->subscription?->plan;
        $currency = app(BillingService::class)->currency($company);
        $floor = $current && ! $current->isFree() ? $current->chargeIn($currency)['amount'] : 0.0;

        return Plan::where('is_active', true)->where('is_public', true)->get()
            ->reject(fn (Plan $p) => $p->isFree() || $p->id === $current?->id || $p->chargeIn($currency)['amount'] <= $floor)
            ->filter(function (Plan $p) use ($key, $needed) {
                $v = array_merge(Entitlements::DEFAULT_LIMITS, $p->limits ?? [])[$key] ?? null;

                return $v === null || $v === '' || (int) $v >= $needed;
            })
            ->sortBy(fn (Plan $p) => $p->chargeIn($currency)['amount'])->first();
    }

    public function featureOn(Company $company, string $feature): bool
    {
        return (bool) (array_merge(Entitlements::DEFAULT_FEATURES, $company->subscription?->plan?->features ?? [])[$feature] ?? false);
    }

    /** Usage block for auth/me and the billing page. */
    public function usage(Company $company): array
    {
        $out = [];
        foreach (self::KEYS as $what => $key) {
            $limit = $this->limit($company, $what);
            $used = $this->used($company, $what);
            $out[$what] = ['used' => $used, 'max' => $limit, 'over' => $limit !== null && $used > $limit];
        }

        return $out;
    }
}

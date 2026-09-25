<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Plan limits (plan A10, P3-7). Online writes are refused with 422
 * `plan_limit_reached`; offline pushes are accepted and flagged (a cashier is
 * never blocked from finishing a sale), and `usage()` rides in auth/me.
 */
class Quotas
{
    public const KEYS = ['users' => 'max_users', 'products' => 'max_products', 'sales' => 'max_sales_per_month', 'storage' => 'storage_mb', 'devices' => 'max_devices'];

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
            'storage' => (int) ceil(((int) DB::table('files')->where('company_id', $company->id)->sum('size_bytes')) / 1048576),
            'devices' => (int) DB::table('devices')->where('company_id', $company->id)->where('status', 'active')->whereNull('revoked_at')->count(),
            default => 0,
        };
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
        if (! $this->allows($company, $what, $adding)) {
            $label = ['users' => 'team members', 'products' => 'products', 'sales' => 'sales this month', 'storage' => 'MB of files', 'devices' => 'phones'][$what];
            $limit = $this->limit($company, $what);
            throw BusinessRuleException::make('plan_limit_reached', "Your plan allows {$limit} {$label}. Upgrade to add more.",
                ['limit' => self::KEYS[$what], 'max' => $limit, 'used' => $this->used($company, $what), 'upgrade_url' => rtrim((string) config('app.url'), '/').'/billing']);
        }
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

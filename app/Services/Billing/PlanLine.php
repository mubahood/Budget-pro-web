<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which plans an account may see and buy (docs/akiba/03-proposal.md §5.1, AK-15). One `plans` table serves the
 * whole family; a plan's line is `features.line`, and a plan without one is on the family line, so the four
 * apps' plans and their listings stay exactly as they were.
 *
 *   family  Budget Pro, Shelfie, Kuku Tally, Pledge & Budget (every plan seeded before Akiba)
 *   akiba   Akiba Ledger, slugs `akiba-*` (AkibaPlanSeeder), for accounts with the exclusive `sacco` module
 *
 * Every place that lists or sells plans narrows by the account's line (the API and classic billing pages,
 * Quotas::planThatFits, the free fallback in Lifecycle, the trial at sign-up), and buying, scheduling or
 * comping a plan of the other line is refused with `plan_line_mismatch`.
 */
final class PlanLine
{
    public const FAMILY = 'family';

    public const AKIBA = 'akiba';

    public const LINES = [self::FAMILY, self::AKIBA];

    /** The module that puts an account on each line other than the family's. */
    private const MODULE_LINES = ['sacco' => self::AKIBA];

    public static function of(Plan $plan): string
    {
        $line = data_get($plan->features, 'line');

        return is_string($line) && in_array($line, self::LINES, true) ? $line : self::FAMILY;
    }

    public static function for(Company $company): string
    {
        return self::forModules($company->modules());
    }

    /** @param  array<int, string>  $modules */
    public static function forModules(array $modules): string
    {
        foreach (self::MODULE_LINES as $module => $line) {
            if (in_array($module, $modules, true)) {
                return $line;
            }
        }

        return self::FAMILY;
    }

    /** A line named by a client (`?line=akiba`), or the family's when it names none it knows. */
    public static function named(?string $line): string
    {
        return in_array($line, self::LINES, true) ? (string) $line : self::FAMILY;
    }

    /**
     * Narrow a plans query to one line. The family line is every plan without an Akiba line, so old rows
     * with no `features` at all stay on it.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, string $line): Builder
    {
        if ($line === self::FAMILY) {
            return $query->where(fn (Builder $q) => $q->whereNull('features')
                ->orWhereNull('features->line')
                ->orWhereNotIn('features->line', array_values(array_diff(self::LINES, [self::FAMILY]))));
        }

        return $query->where('features->line', $line);
    }

    public static function matches(Plan $plan, Company $company): bool
    {
        return self::of($plan) === self::for($company);
    }

    /** Refuse a plan of the other line for this account (checkout, quotes, scheduled changes, comps). */
    public static function assertFor(Plan $plan, Company $company): void
    {
        if (! self::matches($plan, $company)) {
            throw BusinessRuleException::make('plan_line_mismatch', self::for($company) === self::AKIBA
                ? 'This plan is not for SACCO accounts. Choose an Akiba Ledger plan.'
                : 'This plan is for Akiba Ledger SACCO accounts. Choose one of your app\'s plans.', ['line' => self::for($company)]);
        }
    }
}

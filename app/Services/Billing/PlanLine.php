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
 *   family   Budget Pro, Shelfie, Kuku Tally, Pledge & Budget (every plan seeded before Akiba)
 *   akiba    Akiba Ledger, slugs `akiba-*` (AkibaPlanSeeder), for accounts with the exclusive `sacco` module
 *   estate-ledger  Estate Ledger, slugs `estate-ledger-*` (EstateLedgerPlanSeeder), for accounts with the exclusive `rentals` module (KR-20)
 *
 * Every place that lists or sells plans narrows by the account's line (the API and classic billing pages,
 * Quotas::planThatFits, the free fallback in Lifecycle, the trial at sign-up), and buying, scheduling or
 * comping a plan of another line is refused with `plan_line_mismatch`.
 */
final class PlanLine
{
    public const FAMILY = 'family';

    public const AKIBA = 'akiba';

    public const ESTATE_LEDGER = 'estate-ledger';

    public const LINES = [self::FAMILY, self::AKIBA, self::ESTATE_LEDGER];

    /** The module that puts an account on each line other than the family's. */
    private const MODULE_LINES = ['sacco' => self::AKIBA, 'rentals' => self::ESTATE_LEDGER];

    /** The app each line other than the family's sells (the platform admin's labels). */
    private const APPS = [self::AKIBA => 'Akiba Ledger', self::ESTATE_LEDGER => 'Estate Ledger'];

    /** What an account of each app line hears for another line's plan: its own app's name only (AK-68, KR-05). */
    private const NOT_FOR = [
        self::AKIBA => 'This plan is not for SACCO accounts. Choose an Akiba Ledger plan.',
        self::ESTATE_LEDGER => 'This plan is not for Estate Ledger accounts. Choose an Estate Ledger plan.',
    ];

    /** What a family account hears for a plan of an app line, by the plan's line. */
    private const FOR_OTHERS = [
        self::AKIBA => 'This plan is for Akiba Ledger SACCO accounts. Choose one of your app\'s plans.',
        self::ESTATE_LEDGER => 'This plan is for Estate Ledger accounts. Choose one of your app\'s plans.',
    ];

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

    /** A line named by a client (`?line=akiba`, `?line=estate-ledger`), or the family's when it names none it knows. */
    public static function named(?string $line): string
    {
        return in_array($line, self::LINES, true) ? (string) $line : self::FAMILY;
    }

    /** The app a line sells, for the platform admin's plan labels; null for the family's (four apps share it). */
    public static function appName(string $line): ?string
    {
        return self::APPS[$line] ?? null;
    }

    /** A plan as the platform admin's pickers list it: "Estate Ledger Free (Estate Ledger)"; a family plan by its name alone. */
    public static function adminLabel(Plan $plan): string
    {
        $app = self::appName(self::of($plan));

        return $app === null ? (string) $plan->name : $plan->name.' ('.$app.')';
    }

    /** The slug of the plan a new account of this line starts on: its trial (Akiba's and Estate Ledger's run 30 days). */
    public static function trialSlug(string $line): string
    {
        return (string) match ($line) {
            self::AKIBA => config('sacco.plans.trial', 'akiba-trial'),
            self::ESTATE_LEDGER => config('rentals.plans.trial', 'estate-ledger-trial'),
            default => config('saas.default_plan', 'trial'),
        };
    }

    /** The slug of the plan a lapsed account of this line falls back to (ADR 0005). */
    public static function freeSlug(string $line): string
    {
        return (string) match ($line) {
            self::AKIBA => config('sacco.plans.free', 'akiba-free'),
            self::ESTATE_LEDGER => config('rentals.plans.free', 'estate-ledger-free'),
            default => config('saas.free_plan', 'free'),
        };
    }

    /**
     * Narrow a plans query to one line. The family line is every plan without an app line, so old rows
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

    /** Refuse a plan of another line for this account (checkout, quotes, scheduled changes, comps). */
    public static function assertFor(Plan $plan, Company $company): void
    {
        if (! self::matches($plan, $company)) {
            $line = self::for($company);
            throw BusinessRuleException::make('plan_line_mismatch', self::NOT_FOR[$line] ?? self::FOR_OTHERS[self::of($plan)], ['line' => $line]);
        }
    }
}

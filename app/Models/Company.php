<?php

namespace App\Models;

use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Company Model
 *
 * Represents a company/organization in the multi-tenant system.
 * Each company has its own isolated data and settings.
 *
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string|null $phone_number
 * @property string|null $phone_number_2
 * @property string|null $email
 * @property string|null $address
 * @property string|null $slogan
 * @property string|null $about
 * @property string|null $logo
 * @property string $currency
 * @property string $status
 * @property string|null $license_package
 * @property \Illuminate\Support\Carbon|null $license_expire
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Company extends Model
{
    use AuditLogger, HasFactory;

    /**
     * Mass-assignable fields. Every write path in this app currently sets
     * properties explicitly (`$company->name = ...`), so this is not relied
     * on for security — it only unblocks legitimate use of Eloquent's
     * create()/update() convenience methods (e.g. in tests) without opening
     * up the sensitive fields (owner_id, status, license_expire) that are
     * managed exclusively through controlled flows (registration, billing).
     */
    protected $fillable = [
        'name', 'email', 'phone_number', 'phone_number_2', 'address',
        'website', 'about', 'slogan', 'logo', 'currency', 'pobox', 'color',
        'facebook', 'twitter',
        'settings_worker_can_create_stock_item',
        'settings_worker_can_create_stock_record',
        'settings_worker_can_create_stock_category',
        'settings_worker_can_view_balance',
        'settings_worker_can_view_stats',
        'negative_stock_policy', 'low_stock_default', 'require_shift', 'receipt_header', 'receipt_footer', 'timezone',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'license_expire' => 'date',
        'onboarding_state' => 'array',
        'enabled_modules' => 'array',
        'payment_methods' => 'array',
        'receipt_channels' => 'array',
    ];

    /** Modules this company uses (plan C4); unset = the business type's modules, or everything when the type is unknown. */
    public function modules(): array
    {
        if (is_array($this->enabled_modules) && $this->enabled_modules !== []) {
            return array_values(array_intersect($this->enabled_modules, array_keys(config('onboarding.modules'))));
        }
        if ($this->business_type && config("onboarding.business_types.{$this->business_type}.modules")) {
            return config("onboarding.business_types.{$this->business_type}.modules");
        }

        return array_keys(config('onboarding.modules'));
    }

    /**
     * Boot method for model events.
     * Handles company creation and update events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Update owner's company_id when company is updated
        static::updated(function (Company $company) {
            if (empty($company->owner_id)) {
                return; // No owner set, skip
            }
            $owner = User::find($company->owner_id);
            if ($owner == null) {
                \Illuminate\Support\Facades\Log::warning('Company updated but owner not found', [
                    'company_id' => $company->id,
                    'owner_id' => $company->owner_id,
                ]);

                return; // Don't crash company edit if owner record is missing
            }
            $owner->company_id = $company->id;
            $owner->save();
        });

        // Set up company on creation
        static::created(function (Company $company) {
            if (empty($company->owner_id)) {
                return; // No owner set, skip
            }
            $owner = User::find($company->owner_id);
            if ($owner == null) {
                \Illuminate\Support\Facades\Log::warning('Company created but owner not found', [
                    'company_id' => $company->id,
                    'owner_id' => $company->owner_id,
                ]);

                return; // Don't crash registration if owner record has issue
            }
            $owner->company_id = $company->id;
            $owner->save();

            // Prepare default account categories
            self::prepare_account_categories($company->id);
        });
    }

    /**
     * The user who owns / administers this company.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * All users (employees) belonging to this company.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'company_id');
    }

    /**
     * Ping Pin's multi-member organisation model (company_members) — added
     * alongside the legacy owner_id/users() relations above, not replacing
     * them. See PLAN.md §2 / DECISIONS.md D1.
     */
    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class);
    }

    public function activeMembers()
    {
        return $this->members()->where('status', 'active');
    }

    /**
     * The company's current subscription (most recent).
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * All subscriptions this company has had.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Ping Pin's own, separate subscription (DECISIONS.md D2) — a company
     * can have a budget-pro subscription, a Ping Pin subscription, both, or
     * neither; the two are never conflated.
     */
    public function pingPinSubscription(): HasOne
    {
        return $this->hasOne(PingPinSubscription::class)->latestOfMany();
    }

    public function pingPinSubscriptions(): HasMany
    {
        return $this->hasMany(PingPinSubscription::class);
    }

    /**
     * Whether this tenant currently has valid, paid-for access.
     *
     * Order of precedence:
     *   1. An explicitly Inactive company never has access.
     *   2. An active/trialing subscription grants access.
     *   3. Fallback for pre-billing tenants: the legacy `license_expire` date.
     *   4. If neither subscription nor licence date exists, access is granted
     *      (legacy tenants created before billing existed).
     */
    public function hasActiveAccess(): bool
    {
        if (strtolower((string) $this->status) === 'inactive') {
            return false;
        }

        // Subscription-based access (only if the table/relationship is available).
        try {
            $subscription = $this->subscription;
            if ($subscription !== null) {
                return $subscription->isActive();
            }
        } catch (\Throwable $e) {
            // subscriptions table not migrated yet — fall through to licence check.
        }

        // Legacy licence-date fallback.
        if ($this->license_expire !== null) {
            return $this->license_expire->endOfDay()->isFuture();
        }

        return true;
    }

    /**
     * When the current access (subscription or legacy licence) ended / will end.
     */
    public function accessEndedAt(): ?\Illuminate\Support\Carbon
    {
        $subscription = $this->subscription;

        if ($subscription !== null) {
            if ($subscription->status === 'trialing') {
                return $subscription->trial_ends_at ?? $subscription->ends_at;
            }

            return $subscription->ends_at;
        }

        return $this->license_expire?->copy()->endOfDay();
    }

    /**
     * Lapsed, but still inside the grace window (config saas.grace_days).
     * Web is read-only, devices keep selling and syncing (DECISIONS.md H5).
     */
    public function isInGracePeriod(): bool
    {
        if ($this->hasActiveAccess() || strtolower((string) $this->status) === 'inactive') {
            return false;
        }

        $ended = $this->accessEndedAt();

        return $ended !== null && $ended->copy()->addDays((int) config('saas.grace_days', 7))->isFuture();
    }

    /** One of: active | grace | expired | inactive. */
    public function accessState(): string
    {
        if (strtolower((string) $this->status) === 'inactive') {
            return 'inactive';
        }
        if ($this->hasActiveAccess()) {
            return 'active';
        }

        return $this->isInGracePeriod() ? 'grace' : 'expired';
    }

    /**
     * Whether this company should be billed as a Ugandan customer (UGX + mobile
     * money). International customers default to card in USD.
     */
    public function isUgandaBilling(): bool
    {
        return strtoupper((string) $this->currency) === 'UGX';
    }

    /**
     * Activate (or renew/upgrade) this company's subscription to a plan after a
     * confirmed payment, extending the period from the later of "now" or the
     * current period end, and keeping the legacy license_expire column in sync.
     */
    public function activateSubscription(Plan $plan, string $provider = 'flutterwave', ?string $providerRef = null, bool $fromNow = false): Subscription
    {
        $subscription = $this->subscription ?? new Subscription(['company_id' => $this->id]);

        // Extend from the current expiry if still in the future (renewal), else from now.
        // A prorated plan change starts a fresh period now (the old days were credited).
        $base = (! $fromNow && $subscription->ends_at && $subscription->ends_at->isFuture())
            ? $subscription->ends_at->copy()
            : now();

        $endsAt = match ($plan->interval) {
            'year' => $base->copy()->addYear(),
            'lifetime' => $base->copy()->addYears(100),
            default => $base->copy()->addMonth(),
        };

        $subscription->company_id = $this->id;
        $subscription->plan_id = $plan->id;
        $subscription->status = 'active';
        $subscription->trial_ends_at = null;
        $subscription->starts_at = $subscription->starts_at ?? now();
        $subscription->ends_at = $endsAt;
        $subscription->canceled_at = null;
        $subscription->provider = $provider;
        if ($providerRef !== null) {
            $subscription->provider_subscription_id = $providerRef;
        }
        $subscription->save();

        // Keep the legacy licence column consistent with the subscription.
        $this->license_expire = $endsAt;
        $this->status = 'Active';
        $this->saveQuietly();

        return $subscription;
    }

    /**
     * Ping Pin's own activation (DECISIONS.md D2) — same extend-from-later-
     * of-now-or-current-expiry logic as activateSubscription(), but this
     * product has no legacy license_expire/status column to keep in sync;
     * pingpin_subscriptions.status is the only source of truth for it.
     */
    public function activatePingPinSubscription(PingPinPlan $plan, string $provider = 'flutterwave', ?string $providerRef = null): PingPinSubscription
    {
        $subscription = $this->pingPinSubscription ?? new PingPinSubscription(['company_id' => $this->id]);

        $base = ($subscription->ends_at && $subscription->ends_at->isFuture())
            ? $subscription->ends_at->copy()
            : now();

        $endsAt = match ($plan->interval) {
            'year' => $base->copy()->addYear(),
            'lifetime' => $base->copy()->addYears(100),
            default => $base->copy()->addMonth(),
        };

        $subscription->company_id = $this->id;
        $subscription->plan_id = $plan->id;
        $subscription->status = 'active';
        $subscription->starts_at = $subscription->starts_at ?? now();
        $subscription->ends_at = $endsAt;
        $subscription->canceled_at = null;
        $subscription->provider = $provider;
        if ($providerRef !== null) {
            $subscription->provider_subscription_id = $providerRef;
        }
        $subscription->save();

        return $subscription;
    }

    /**
     * Prepare default account categories for a new company.
     * Creates standard income and expense categories.
     *
     * @param  int  $company_id  The ID of the company
     * @return void
     */
    public static function prepare_account_categories($company_id)
    {
        $company = Company::find($company_id);
        if ($company == null) {
            throw new \Exception('Company not found');
        }
        $sales_account_category = FinancialCategory::where([
            ['company_id', '=', $company_id],
            ['name', '=', 'Sales'],
        ])->first();
        if ($sales_account_category == null) {
            $sales_account_category = new FinancialCategory();
            $sales_account_category->company_id = $company_id;
            $sales_account_category->name = 'Sales';
            $sales_account_category->save();
        }

        $purchase_account_category = FinancialCategory::where([
            ['company_id', '=', $company_id],
            ['name', '=', 'Purchase'],
        ])->first();
        if ($purchase_account_category == null) {
            $purchase_account_category = new FinancialCategory();
            $purchase_account_category->company_id = $company_id;
            $purchase_account_category->name = 'Purchase';
            $purchase_account_category->save();
        }

        $expense_account_category = FinancialCategory::where([
            ['company_id', '=', $company_id],
            ['name', '=', 'Expense'],
        ])->first();

        if ($expense_account_category == null) {
            $expense_account_category = new FinancialCategory();
            $expense_account_category->company_id = $company_id;
            $expense_account_category->name = 'Expense';
            $expense_account_category->save();
        }
    }
}

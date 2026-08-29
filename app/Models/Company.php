<?php

namespace App\Models;

use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
 *
 * @package App\Models
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
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'license_expire' => 'date',
    ];

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
        static::updated(function ($company) {
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
        static::created(function ($company) {
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
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * All users (employees) belonging to this company.
     */
    public function users()
    {
        return $this->hasMany(User::class, 'company_id');
    }

    /**
     * Ping Pin's multi-member organisation model (company_members) — added
     * alongside the legacy owner_id/users() relations above, not replacing
     * them. See PLAN.md §2 / DECISIONS.md D1.
     */
    public function members()
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
    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * All subscriptions this company has had.
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
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
    public function activateSubscription(Plan $plan, string $provider = 'flutterwave', ?string $providerRef = null): Subscription
    {
        $subscription = $this->subscription ?? new Subscription(['company_id' => $this->id]);

        // Extend from the current expiry if still in the future (renewal), else from now.
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

        // Keep the legacy licence column consistent with the subscription.
        $this->license_expire = $endsAt;
        $this->status = 'Active';
        $this->saveQuietly();

        return $subscription;
    }

    /**
     * Prepare default account categories for a new company.
     * Creates standard income and expense categories.
     *
     * @param int $company_id The ID of the company
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

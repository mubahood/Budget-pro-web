<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's subscription to a plan.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $plan_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property \Illuminate\Support\Carbon|null $canceled_at
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'plan_id', 'status', 'trial_ends_at', 'starts_at', 'ends_at',
        'canceled_at', 'provider', 'provider_subscription_id', 'provider_customer_id', 'meta',
        'billing_interval', 'pending_plan_id', 'pending_change_at', 'auto_renew',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'pending_change_at' => 'datetime',
        'auto_renew' => 'boolean',
        'meta' => 'array',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Plan, Subscription> */
    public function pendingPlan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /** month | year (older rows have no column value yet: month). */
    public function interval(): string
    {
        return $this->billing_interval === 'year' ? 'year' : 'month';
    }

    /** A paid plan whose period is still running (active or cancelled-but-not-ended). */
    public function isPaidAndRunning(): bool
    {
        return in_array($this->status, ['active', 'canceled'], true) && $this->ends_at !== null && $this->ends_at->isFuture()
            && $this->plan !== null && ! $this->plan->isFree();
    }

    /** The saved card for auto-renew (Flutterwave token + masked details), if any. */
    public function savedCard(): ?array
    {
        $card = data_get($this->meta, 'card');

        return is_array($card) && ! empty($card['token']) ? $card : null;
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Plan, Subscription> */
    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * True while the subscription grants access (trialing within trial window,
     * or active/past_due within the current period).
     */
    public function isActive(): bool
    {
        if (in_array($this->status, ['canceled', 'expired'], true)) {
            // Canceled subscriptions still grant access until the period ends.
            return $this->ends_at !== null && $this->ends_at->isFuture();
        }

        if ($this->status === 'trialing') {
            return $this->trial_ends_at === null || $this->trial_ends_at->isFuture();
        }

        if (in_array($this->status, ['active', 'past_due'], true)) {
            return $this->ends_at === null || $this->ends_at->isFuture();
        }

        return false;
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }
}

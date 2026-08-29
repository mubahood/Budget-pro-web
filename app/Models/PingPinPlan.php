<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ping Pin's own plan table (DECISIONS.md D2) — same proven shape and
 * helper methods as App\Models\Plan, deliberately not shared code, so a
 * change to one product's billing logic can never accidentally affect
 * the other's.
 */
class PingPinPlan extends Model
{
    use HasFactory;

    // Eloquent's naming convention would guess `ping_pin_plans` — see
    // PingPinDeviceConsent's docblock for why every PingPin* model needs this.
    protected $table = 'pingpin_plans';

    protected $fillable = [
        'name', 'slug', 'description', 'price', 'price_ugx', 'currency', 'interval',
        'trial_days', 'is_active', 'is_public', 'sort_order', 'features', 'limits',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_ugx' => 'decimal:2',
        'trial_days' => 'integer',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
        'features' => 'array',
        'limits' => 'array',
    ];

    public function subscriptions()
    {
        return $this->hasMany(PingPinSubscription::class, 'plan_id');
    }

    public function allowsFeature(string $feature): bool
    {
        return (bool) data_get($this->features, $feature, false);
    }

    public function limit(string $key): ?int
    {
        $value = data_get($this->limits, $key);

        return $value === null ? null : (int) $value;
    }

    /** @return array{amount: float, currency: string} */
    public function chargeFor(bool $isUganda): array
    {
        if ($isUganda) {
            return ['amount' => (float) $this->price_ugx, 'currency' => 'UGX'];
        }

        return ['amount' => (float) $this->price, 'currency' => (string) config('flutterwave.international_currency', 'USD')];
    }
}

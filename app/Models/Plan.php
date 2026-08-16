<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Subscription Plan.
 *
 * Defines a purchasable tier with a price, billing interval, feature flags and
 * usage limits (quotas). Feature flags gate functionality; limits cap usage.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property float $price
 * @property string $currency
 * @property string $interval
 * @property int $trial_days
 * @property bool $is_active
 * @property array|null $features
 * @property array|null $limits
 */
class Plan extends Model
{
    use HasFactory;

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
        return $this->hasMany(Subscription::class);
    }

    /**
     * Whether this plan enables a named feature flag.
     */
    public function allowsFeature(string $feature): bool
    {
        return (bool) data_get($this->features, $feature, false);
    }

    /**
     * The numeric limit for a named quota, or null when unlimited/undefined.
     */
    public function limit(string $key): ?int
    {
        $value = data_get($this->limits, $key);

        return $value === null ? null : (int) $value;
    }

    /**
     * The amount + currency to charge for this plan, given whether the customer
     * is billed in Uganda (UGX/mobile money) or internationally (USD/card).
     *
     * @return array{amount: float, currency: string}
     */
    public function chargeFor(bool $isUganda): array
    {
        if ($isUganda) {
            return [
                'amount' => (float) $this->price_ugx,
                'currency' => 'UGX',
            ];
        }

        return [
            'amount' => (float) $this->price,
            'currency' => (string) config('flutterwave.international_currency', 'USD'),
        ];
    }
}

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
        'name', 'slug', 'description', 'price', 'price_ugx', 'price_ugx_annual', 'currency', 'interval',
        'trial_days', 'is_active', 'is_public', 'sort_order', 'features', 'limits', 'prices',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_ugx' => 'decimal:2',
        'price_ugx_annual' => 'decimal:2',
        'trial_days' => 'integer',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
        'features' => 'array',
        'limits' => 'array',
        'prices' => 'array',
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

    public const INTERVALS = ['month', 'year'];

    /** How many monthly prices an annual price is worth by default ("2 months free"). */
    public const ANNUAL_MONTHS = 10;

    /**
     * The amount + currency to charge for this plan in a company's currency and billing interval
     * (POWER_PLAN §4.2). A local price (`prices.KES` = 2500 or {month, year}) is used when the plan has
     * one; otherwise UGX, or USD for everyone else. Annual defaults to 10 × the monthly price.
     *
     * @return array{amount: float, currency: string}
     */
    public function chargeIn(string $currency, string $interval = 'month'): array
    {
        $currency = strtoupper($currency);
        $year = $interval === 'year' && $this->interval === 'month';
        $local = $currency !== 'UGX' ? $this->localPrice($currency, $year) : null;
        if ($local !== null) {
            return ['amount' => $local, 'currency' => $currency];
        }
        $base = $this->chargeFor($currency === 'UGX');
        if ($year) {
            $annual = $currency === 'UGX' ? (float) $this->price_ugx_annual : (float) data_get($this->prices, 'USD.year', 0);
            $base['amount'] = $annual > 0 ? $annual : round($base['amount'] * self::ANNUAL_MONTHS, 2);
        }

        return $base;
    }

    /** A price from the per-currency `prices` json: a bare number (monthly) or {month, year}. */
    private function localPrice(string $currency, bool $year): ?float
    {
        $entry = data_get($this->prices, $currency);
        $month = (float) (is_array($entry) ? ($entry['month'] ?? 0) : $entry);
        $price = $month;
        if ($year) {
            $annual = is_array($entry) ? (float) ($entry['year'] ?? 0) : 0.0;
            $price = $annual > 0 ? $annual : $month * self::ANNUAL_MONTHS;
        }

        return $price > 0 ? round($price, 2) : null;
    }

    /** The monthly price in a currency (for "per month" and "about … a day" labels). */
    public function monthlyPrice(string $currency, string $interval = 'month'): float
    {
        $amount = $this->chargeIn($currency, $interval)['amount'];

        return $interval === 'year' ? round($amount / 12, 2) : $amount;
    }

    public function isFree(): bool
    {
        return (float) $this->price <= 0 && (float) $this->price_ugx <= 0;
    }

    public function periodDays(?string $interval = null): int
    {
        if ($interval === 'year' && $this->interval === 'month') {
            return 365;
        }

        return match ($this->interval) {
            'year' => 365,
            'lifetime' => 36500,
            default => 30,
        };
    }

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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a standalone copy sells for (Platform › Standalone products; docs/STANDALONE_EDITION_PLAN.md
 * §11, §22). SaaS only. A price change never reaches an order already made: the order snapshots it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $price_ugx
 * @property string|null $price_usd
 * @property string|null $renewal_price_ugx  empty: renewals by arrangement, not online
 * @property string|null $renewal_price_usd
 * @property int $updates_months
 * @property array<string,int|null>|null $limits {max_users, max_locations, max_devices}; null = unlimited
 * @property bool $white_label
 * @property bool $is_active
 * @property int $sort
 */
class StandaloneProduct extends Model
{
    public const CURRENCIES = ['UGX', 'USD'];

    /** Limit keys a licence may carry, with how they read: [one, many]. Never products or sales. */
    public const LIMITS = [
        'max_users' => ['team login', 'team logins'],
        'max_locations' => ['store', 'stores'],
        'max_devices' => ['phone', 'phones'],
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'price_ugx', 'price_usd', 'renewal_price_ugx', 'renewal_price_usd',
        'updates_months', 'limits', 'white_label', 'is_active', 'sort',
    ];

    protected $casts = [
        'price_ugx' => 'decimal:2', 'price_usd' => 'decimal:2', 'renewal_price_ugx' => 'decimal:2', 'renewal_price_usd' => 'decimal:2',
        'updates_months' => 'integer', 'limits' => 'array', 'white_label' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer',
    ];

    public function scopeOnSale(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('price_ugx');
    }

    /** The purchase price in a currency, or null when the product is not sold in it. */
    public function price(string $currency): ?float
    {
        $value = $currency === 'USD' ? $this->price_usd : ($currency === 'UGX' ? $this->price_ugx : null);

        return $value === null ? null : (float) $value;
    }

    /** Another period of updates in a currency, or null when renewals are by arrangement. */
    public function renewalPrice(string $currency): ?float
    {
        $value = $currency === 'USD' ? $this->renewal_price_usd : ($currency === 'UGX' ? $this->renewal_price_ugx : null);

        return $value === null ? null : (float) $value;
    }

    /** The limits as a licence carries them: every key present, a whole number or null (unlimited). @return array<string,int|null> */
    public function licenceLimits(): array
    {
        return self::normaliseLimits($this->limits);
    }

    /** @return array<string,int|null> */
    public static function normaliseLimits(?array $limits): array
    {
        $out = [];
        foreach (array_keys(self::LIMITS) as $key) {
            $value = $limits[$key] ?? null;
            $out[$key] = $value === null || $value === '' ? null : max(0, (int) $value);
        }

        return $out;
    }

    /** "Up to 5 team logins, 1 store and 5 phones", or "Unlimited team logins, stores and phones". */
    public static function describeLimits(?array $limits): string
    {
        $limits = self::normaliseLimits($limits);
        $set = $free = [];
        foreach (self::LIMITS as $key => [$one, $many]) {
            if ($limits[$key] === null) {
                $free[] = $many;
            } else {
                $set[] = number_format($limits[$key]).' '.($limits[$key] === 1 ? $one : $many);
            }
        }
        $join = fn (array $parts) => count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)).' and '.end($parts) : (string) ($parts[0] ?? '');
        $text = $set !== [] ? 'Up to '.$join($set) : '';
        if ($free !== []) {
            $text .= ($text !== '' ? '; unlimited ' : 'Unlimited ').$join($free);
        }

        return $text;
    }

    public static function money(string $currency, float $amount): string
    {
        return $currency === 'USD' ? 'US$'.number_format($amount, floor($amount) == $amount ? 0 : 2) : $currency.' '.number_format($amount);
    }
}

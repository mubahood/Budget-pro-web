<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Template packs (plan C3) from database/data/product_templates.php — idempotent (updateOrInsert on
 * business type + country + name). Each row carries prices in UGX, KES, TZS and RWF (converted with the
 * data file's fixed rates and rounded to shelf prices), so a shop in any supported country gets a
 * priced pack in its own currency.
 */
class ProductTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('data/product_templates.php');
        foreach ($data['packs'] as $type => $rows) {
            // A product dropped from a pack is switched off, not deleted (nothing refers to it, but history reads better).
            DB::table('product_templates')->where('business_type', $type)
                ->whereNotIn('name', array_column($rows, 0))->update(['is_active' => false, 'updated_at' => now()]);
            // Products only East-African shops know carry country 'UG' (shown to the East-African region);
            // every other product has no country and is offered to shops anywhere.
            $regional = array_flip($data['regional'][$type] ?? []);
            foreach ($rows as $i => $row) {
                [$name, $cat, $sub, $unit] = $row;
                $country = isset($regional[$name]) || preg_match(self::REGIONAL_NAMES, $name) ? 'UG' : null;
                $existing = DB::table('product_templates')->where(['business_type' => $type, 'name' => $name])->orderBy('id')->pluck('id');
                $values = ['country' => $country, 'pack_version' => $data['version'], 'category' => $cat, 'sub_category' => $sub, 'unit' => $unit, 'sort_order' => $i,
                    'prices' => json_encode(self::prices($row, $data['rates'] ?? ['UGX' => 1])), 'is_active' => true, 'updated_at' => now()];
                if ($existing->isEmpty()) {
                    DB::table('product_templates')->insert(['business_type' => $type, 'name' => $name, 'created_at' => now()] + $values);
                } else {
                    DB::table('product_templates')->where('id', $existing->first())->update($values);
                    DB::table('product_templates')->whereIn('id', $existing->slice(1))->update(['is_active' => false]); // an older duplicate
                }
            }
        }
    }

    /**
     * {"UGX": {"sell": 5000, "cost": 4400}, "KES": {"sell": 180, "cost": 155}, …}
     *
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: int|float, 5: int|float, 6?: array<string, array{sell: int|float, cost: int|float}>}  $row
     * @param  array<string, float|int>  $rates
     * @return array<string, array{sell: int|float, cost: int|float}>
     */
    public static function prices(array $row, array $rates): array
    {
        $sellUgx = (float) $row[4];
        $costUgx = (float) $row[5];
        $out = [];
        foreach ($rates as $currency => $rate) {
            if ($currency === 'UGX') {
                $out['UGX'] = ['sell' => $row[4], 'cost' => $row[5]];

                continue;
            }
            $cents = in_array($currency, self::CENTS, true);
            $sell = $cents ? self::niceCents($sellUgx * $rate) : self::nice($sellUgx * $rate);
            $cost = $costUgx > 0 ? ($cents ? self::niceCents($costUgx * $rate) : self::nice($costUgx * $rate)) : 0;
            if ($costUgx > 0 && $costUgx < $sellUgx && $cost >= $sell) {
                $cost = $cents ? max(0, round($sell * 0.85, 2)) : max(0, $sell - self::step($sell)); // keep a margin after rounding
            }
            $out[$currency] = ['sell' => $sell, 'cost' => $cost];
        }
        foreach ($row[6] ?? [] as $currency => $p) {
            $out[$currency] = $p;
        }

        return $out;
    }

    /** Also East-African wherever they appear: airtime sold in shilling amounts ("Airtime 1,000", "MTN airtime 5,000"). */
    public const REGIONAL_NAMES = '/^(mtn |airtel |safaricom )?airtime [0-9]/i';

    /** Currencies priced with cents on the shelf ($1.25, €0.45). */
    public const CENTS = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'SAR', 'ZAR', 'GHS', 'ZMW', 'EGP'];

    /** 0.274 → 0.25, 1.37 → 1.40, 12.3 → 12.50, 143 → 145: a shelf price in a currency with cents. */
    public static function niceCents(float $v): float
    {
        if ($v <= 0) {
            return 0;
        }
        $step = match (true) {
            $v < 1 => 0.05,
            $v < 20 => 0.1,
            $v < 100 => 0.5,
            $v < 1000 => 5,
            default => 10,
        };

        return round(max($step, round($v / $step) * $step), 2);
    }

    /** A price a shop would write on a shelf: 178.6 → 180, 3,493 → 3,500, 38 → 40. */
    public static function nice(float $v): int
    {
        if ($v <= 0) {
            return 0;
        }
        $step = self::step($v);

        return (int) max($step, round($v / $step) * $step);
    }

    private static function step(float $v): int
    {
        return match (true) {
            $v < 10 => 1,
            $v < 100 => 5,
            $v < 1000 => 10,
            $v < 10000 => 50,
            $v < 100000 => 500,
            default => 1000,
        };
    }
}

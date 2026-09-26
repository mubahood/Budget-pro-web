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
            foreach ($rows as $i => $row) {
                [$name, $cat, $sub, $unit] = $row;
                DB::table('product_templates')->updateOrInsert(
                    ['business_type' => $type, 'country' => 'UG', 'name' => $name],
                    ['pack_version' => $data['version'], 'category' => $cat, 'sub_category' => $sub, 'unit' => $unit, 'sort_order' => $i,
                        'prices' => json_encode(self::prices($row, $data['rates'] ?? ['UGX' => 1])), 'is_active' => true, 'updated_at' => now()]
                );
                DB::table('product_templates')->where(['business_type' => $type, 'country' => 'UG', 'name' => $name])->whereNull('created_at')->update(['created_at' => now()]);
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
            $sell = self::nice($sellUgx * $rate);
            $cost = $costUgx > 0 ? self::nice($costUgx * $rate) : 0;
            if ($costUgx > 0 && $costUgx < $sellUgx && $cost >= $sell) {
                $cost = max(0, $sell - self::step($sell)); // keep a margin after rounding
            }
            $out[$currency] = ['sell' => $sell, 'cost' => $cost];
        }
        foreach ($row[6] ?? [] as $currency => $p) {
            $out[$currency] = $p;
        }

        return $out;
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

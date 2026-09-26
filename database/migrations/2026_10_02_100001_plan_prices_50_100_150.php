<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New public prices (owner decision, 2026-09-26): Starter 50,000, Business 100,000, Enterprise 150,000 UGX a month.
 * The yearly price stays "two months free" (10 × monthly). The USD figure is the rounded equivalent.
 * Existing subscriptions keep the amount they were charged; the new price applies from the next renewal.
 */
return new class extends Migration
{
    private const PRICES = [
        'starter' => [50000, 14],
        'business' => [100000, 27],
        'enterprise' => [150000, 40],
    ];

    public function up(): void
    {
        foreach (self::PRICES as $slug => [$ugx, $usd]) {
            DB::table('plans')->where('slug', $slug)->update([
                'price_ugx' => $ugx,
                'price_ugx_annual' => $ugx * 10,
                'price' => $usd,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['starter' => [70000, 19], 'business' => [185000, 49], 'enterprise' => [560000, 149]] as $slug => [$ugx, $usd]) {
            DB::table('plans')->where('slug', $slug)->update(['price_ugx' => $ugx, 'price_ugx_annual' => $ugx * 10, 'price' => $usd]);
        }
    }
};

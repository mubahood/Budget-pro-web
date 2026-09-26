<?php

namespace Tests\Feature\Api;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Plan limits use the keys the enforcement reads (max_products, max_locations…), and a deploy's
 * db:seed never overwrites a plan a platform admin edited.
 */
class PlanLimitsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeded_plans_carry_the_enforced_keys_and_seeding_keeps_admin_edits(): void
    {
        $this->seed(PlanSeeder::class);
        foreach (['trial', 'starter', 'business', 'enterprise'] as $slug) {
            $limits = (array) Plan::where('slug', $slug)->value('limits');
            $this->assertArrayHasKey('max_products', $limits, $slug);
            $this->assertArrayNotHasKey('max_stock_items', $limits, $slug);
            $this->assertArrayHasKey('max_locations', $limits, $slug);
        }
        $this->assertTrue((bool) (Plan::where('slug', 'business')->first()->features['multi_location'] ?? false));

        // An admin raises Starter's product limit; the next deploy's seeding keeps it.
        $starter = Plan::where('slug', 'starter')->first();
        $starter->limits = ['max_products' => 750] + (array) $starter->limits;
        $starter->save();
        $this->seed(PlanSeeder::class);
        $this->assertSame(750, (int) Plan::where('slug', 'starter')->first()->limits['max_products']);
    }

    public function test_an_old_plan_row_is_renamed_to_the_enforced_key(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', 'starter')->first();
        $plan->limits = ['max_users' => 3, 'max_stock_items' => 500];
        $plan->save();
        $migration = require base_path('database/migrations/2026_09_30_300001_plan_limits_keys.php');
        $migration->up();
        $limits = (array) $plan->fresh()->limits;
        $this->assertSame(500, (int) $limits['max_products']);
        $this->assertArrayNotHasKey('max_stock_items', $limits);
        $this->assertSame(1, (int) $limits['max_locations']);
    }
}

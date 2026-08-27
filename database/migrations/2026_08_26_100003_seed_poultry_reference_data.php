<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the same 3 farm types + starter guide the mobile app already
     * seeds locally on first launch (lib/poultry/data/entities/farm_type.dart
     * and production_guide_task.dart in budget-pro-mobo), so once real sync
     * lands, a fresh client and a pulled-from-server client show identical
     * content. idempotent — safe to re-run.
     */
    public function up(): void
    {
        $now = now();

        $farmTypes = [
            ['slug' => 'layer', 'name' => 'Layers', 'description' => 'Birds kept for egg production.'],
            ['slug' => 'broiler', 'name' => 'Broilers', 'description' => 'Birds raised for meat.'],
            ['slug' => 'kienyeji', 'name' => 'Kienyeji (local)', 'description' => 'Free-range/local breed, dual-purpose.'],
        ];

        foreach ($farmTypes as $type) {
            DB::table('poultry_farm_types')->updateOrInsert(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $tasks = [
            ['layer', 'Set up brooder', 'Brooder temperature ~32–35°C, clean water and starter feed ready.', 0],
            ['layer', 'First deworming check', 'Check with your vet on a deworming schedule.', 14],
            ['layer', 'Switch to growers mash', 'Transition off chick mash once birds reach ~6 weeks.', 42],
            ['layer', 'Switch to layers mash', 'Higher-calcium feed as birds approach point-of-lay.', 112],
            ['layer', 'Expect first eggs', 'Set up nest boxes; point-of-lay is typically around 18 weeks.', 126],

            ['broiler', 'Set up brooder', 'Brooder temperature ~32–35°C, clean water and starter feed ready.', 0],
            ['broiler', 'Switch to grower feed', 'Transition off chick mash once birds reach ~2 weeks.', 14],
            ['broiler', 'Weigh a sample of birds', 'Track average weight to monitor growth and feed conversion.', 21],
            ['broiler', 'Plan for market weight', 'Most broilers reach market weight around 5–6 weeks — start planning sales.', 35],

            ['kienyeji', 'Set up brooder / free-range area', 'Secure area with shelter, clean water and starter feed.', 0],
            ['kienyeji', 'Start supplementing with grower feed', 'Supplement natural foraging as birds grow.', 21],
            ['kienyeji', 'Assess growth and health', 'Check body condition and general flock health.', 60],
        ];

        foreach ($tasks as [$slug, $title, $description, $days]) {
            $exists = DB::table('poultry_production_guide_tasks')
                ->where('farm_type_slug', $slug)
                ->where('title', $title)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('poultry_production_guide_tasks')->insert([
                'farm_type_slug' => $slug,
                'title' => $title,
                'description' => $description,
                'days_after_start' => $days,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Reference data seed — intentionally not reversed by dropping rows,
        // since an admin may have already edited them via the CRUD by the
        // time this migration is ever rolled back.
    }
};

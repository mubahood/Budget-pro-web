<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Full poultry-management sidebar entries, nested under the existing
     * "Poultry Management" parent (added by 2026_08_26_100004). Idempotent
     * on `uri` — safe to re-run.
     */
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'poultry-batches')->exists()) {
            return;
        }

        $parent = DB::table('admin_menu')->where('title', 'Poultry Management')->where('parent_id', 0)->first();
        if (! $parent) {
            return; // parent menu missing — nothing sane to nest under, skip rather than create a duplicate root
        }

        $now = now();
        $order = (int) DB::table('admin_menu')->max('order');

        $items = [
            ['title' => 'Batches', 'icon' => 'fa-cubes', 'uri' => 'poultry-batches'],
            ['title' => 'Daily Records', 'icon' => 'fa-calendar-check-o', 'uri' => 'poultry-daily-records'],
            ['title' => 'Sales', 'icon' => 'fa-credit-card', 'uri' => 'poultry-sales'],
            ['title' => 'Customers', 'icon' => 'fa-address-book', 'uri' => 'poultry-customers'],
            ['title' => 'Expenses', 'icon' => 'fa-money', 'uri' => 'poultry-expenses'],
            ['title' => 'Feed Types', 'icon' => 'fa-flask', 'uri' => 'poultry-feed-types'],
            ['title' => 'Feed Stock', 'icon' => 'fa-archive', 'uri' => 'poultry-feed-stock'],
            ['title' => 'Egg Transactions', 'icon' => 'fa-circle-o', 'uri' => 'poultry-egg-transactions'],
            ['title' => 'Mortality Events', 'icon' => 'fa-heartbeat', 'uri' => 'poultry-mortality-events'],
            ['title' => 'Health Events', 'icon' => 'fa-medkit', 'uri' => 'poultry-health-events'],
            ['title' => 'Vaccinations', 'icon' => 'fa-syringe', 'uri' => 'poultry-vaccination-events'],
        ];

        foreach ($items as $i => $item) {
            DB::table('admin_menu')->insert([
                'parent_id' => $parent->id,
                'order' => $order + $i + 1,
                'title' => $item['title'],
                'icon' => $item['icon'],
                'uri' => $item['uri'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('admin_menu')->whereIn('uri', [
            'poultry-batches', 'poultry-daily-records', 'poultry-sales', 'poultry-customers',
            'poultry-expenses', 'poultry-feed-types', 'poultry-feed-stock', 'poultry-egg-transactions',
            'poultry-mortality-events', 'poultry-health-events', 'poultry-vaccination-events',
        ])->delete();
    }
};

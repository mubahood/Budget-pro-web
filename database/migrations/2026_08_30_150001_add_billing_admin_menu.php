<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * budget-pro's own `plans`/`subscriptions` tables had no admin menu entry at
 * all (PlanController/SubscriptionController are new — see those files).
 * Adds a top-level "Billing" parent with "Plans" and "Subscriptions"
 * children, matching every other module's menu-migration pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'plans')->exists()) {
            return;
        }

        $maxOrder = (int) DB::table('admin_menu')->max('order');

        $parentId = DB::table('admin_menu')->insertGetId([
            'parent_id' => 0,
            'order' => $maxOrder + 1,
            'title' => 'Billing',
            'icon' => 'fa-credit-card',
            'uri' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('admin_menu')->insert([
            [
                'parent_id' => $parentId,
                'order' => $maxOrder + 2,
                'title' => 'Plans',
                'icon' => 'fa-money',
                'uri' => 'plans',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'parent_id' => $parentId,
                'order' => $maxOrder + 3,
                'title' => 'Subscriptions',
                'icon' => 'fa-refresh',
                'uri' => 'subscriptions',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        $parent = DB::table('admin_menu')->where('title', 'Billing')->where('parent_id', 0)->first();
        if ($parent) {
            DB::table('admin_menu')->where('parent_id', $parent->id)->delete();
            DB::table('admin_menu')->where('id', $parent->id)->delete();
        }
    }
};

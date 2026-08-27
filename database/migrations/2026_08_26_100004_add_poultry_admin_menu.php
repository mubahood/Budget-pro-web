<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the sidebar entries for the new Poultry reference-data module.
     * laravel-admin's menu is DB-driven (admin_menu table), not code-driven —
     * no existing module in this codebase adds its menu rows via migration
     * (they were all added by hand through /admin/auth/menu), but doing it
     * here means the module is visible immediately after deploy with no
     * manual admin step. Idempotent on `uri` — safe to re-run.
     */
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'poultry-farm-types')->exists()) {
            return;
        }

        $now = now();
        $maxOrder = (int) DB::table('admin_menu')->max('order');

        $parentId = DB::table('admin_menu')->insertGetId([
            'parent_id' => 0,
            'order' => $maxOrder + 1,
            'title' => 'Poultry Management',
            'icon' => 'fa-feather',
            'uri' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('admin_menu')->insert([
            [
                'parent_id' => $parentId,
                'order' => $maxOrder + 2,
                'title' => 'Farm Types',
                'icon' => 'fa-leaf',
                'uri' => 'poultry-farm-types',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'parent_id' => $parentId,
                'order' => $maxOrder + 3,
                'title' => 'Production Guide Tasks',
                'icon' => 'fa-tasks',
                'uri' => 'poultry-production-guide-tasks',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        $parent = DB::table('admin_menu')->where('title', 'Poultry Management')->where('parent_id', 0)->first();
        DB::table('admin_menu')->whereIn('uri', ['poultry-farm-types', 'poultry-production-guide-tasks'])->delete();
        if ($parent) {
            DB::table('admin_menu')->where('id', $parent->id)->delete();
        }
    }
};

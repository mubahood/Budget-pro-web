<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform › Standalone products and Standalone licences (docs/STANDALONE_EDITION_PLAN.md §10, §11),
 * under the "Admin" section beside Standalone releases. The routes are edition:saas +
 * admin.platform:all, so the entries are a 404 on a standalone copy even if shown.
 */
return new class extends Migration
{
    private const ITEMS = [
        'standalone-products' => ['Standalone products', 'fa-tags'],
        'standalone-licences' => ['Standalone licences', 'fa-certificate'],
    ];

    public function up(): void
    {
        $admin = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id');
        if (! $admin) {
            return; // a database without the classic menus (a fresh standalone install seeds its own)
        }
        foreach (self::ITEMS as $uri => [$title, $icon]) {
            if (DB::table('admin_menu')->where('uri', $uri)->exists()) {
                continue;
            }
            DB::table('admin_menu')->insert([
                'parent_id' => $admin, 'order' => (int) DB::table('admin_menu')->where('parent_id', $admin)->max('order') + 1,
                'title' => $title, 'icon' => $icon, 'uri' => $uri, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('admin_menu')->whereIn('uri', array_keys(self::ITEMS))->delete();
    }
};

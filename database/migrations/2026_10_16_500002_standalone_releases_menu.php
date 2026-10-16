<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform › Standalone releases (docs/STANDALONE_EDITION_PLAN.md §10), under the "Admin" section
 * that only the administrator role sees (like Product templates and System health). The routes are
 * edition:saas + admin.platform:all, so the entry is a 404 on a standalone copy even if shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'standalone-releases')->exists()) {
            return;
        }
        $admin = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id');
        if (! $admin) {
            return; // a database without the classic menus (a fresh standalone install seeds its own)
        }
        DB::table('admin_menu')->insert([
            'parent_id' => $admin, 'order' => (int) DB::table('admin_menu')->where('parent_id', $admin)->max('order') + 1,
            'title' => 'Standalone releases', 'icon' => 'fa-archive', 'uri' => 'standalone-releases',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'standalone-releases')->delete();
    }
};

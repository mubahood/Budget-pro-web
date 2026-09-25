<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** P4-2: the Reports page in the tenant menu (under Shop), scoped like the other shop menus. */
return new class extends Migration
{
    public function up(): void
    {
        $shop = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Shop')->value('id');
        if ($shop && ! DB::table('admin_menu')->where('uri', 'reports')->exists()) {
            DB::table('admin_menu')->insert(['parent_id' => $shop, 'order' => (int) DB::table('admin_menu')->where('parent_id', $shop)->max('order') + 1,
                'title' => 'Reports', 'icon' => 'fa-bar-chart', 'uri' => 'reports', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (DB::table('admin_roles')->exists()) {
            \App\Support\AdminAccess::ensureShopRoles();
        }
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'reports')->delete();
    }
};

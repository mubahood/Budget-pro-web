<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Platform › Learn videos, beside Promo videos under "Admin" (routes: edition:saas + admin.platform:all). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'learn-videos')->exists()) {
            return;
        }
        $admin = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id');
        if (! $admin) {
            return;
        }
        DB::table('admin_menu')->insert([
            'parent_id' => $admin, 'order' => (int) DB::table('admin_menu')->where('parent_id', $admin)->max('order') + 1,
            'title' => 'Learn videos', 'icon' => 'fa-graduation-cap', 'uri' => 'learn-videos',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'learn-videos')->delete();
    }
};

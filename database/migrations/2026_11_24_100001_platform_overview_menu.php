<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Admin › Platform overview, first in the "Admin" section only the administrator role sees (routes: edition:saas + admin.platform:all). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'platform-overview')->exists()) {
            return;
        }
        $admin = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id');
        if (! $admin) {
            return;
        }
        DB::table('admin_menu')->insert([
            'parent_id' => $admin, 'order' => (int) DB::table('admin_menu')->where('parent_id', $admin)->min('order') - 1,
            'title' => 'Platform overview', 'icon' => 'fa-globe', 'uri' => 'platform-overview',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'platform-overview')->delete();
    }
};

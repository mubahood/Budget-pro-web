<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Admin › Same person twice, right after Platform overview (routes: edition:saas + admin.platform:all). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'same-person-twice')->exists()) {
            return;
        }
        $admin = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id');
        if (! $admin) {
            return;
        }
        $after = DB::table('admin_menu')->where('uri', 'platform-overview')->value('order');
        DB::table('admin_menu')->insert([
            'parent_id' => $admin, 'order' => $after !== null ? (int) $after : (int) DB::table('admin_menu')->where('parent_id', $admin)->min('order') - 1,
            'title' => 'Same person twice', 'icon' => 'fa-clone', 'uri' => 'same-person-twice',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'same-person-twice')->delete();
    }
};

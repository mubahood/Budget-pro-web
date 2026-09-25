<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Phase 5: "Mobile money & reminders" under Shop. */
return new class extends Migration
{
    public function up(): void
    {
        $shop = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Shop')->value('id');
        if ($shop && ! DB::table('admin_menu')->where('uri', 'engagement')->exists()) {
            DB::table('admin_menu')->insert(['parent_id' => $shop, 'order' => (int) DB::table('admin_menu')->where('parent_id', $shop)->max('order') + 1,
                'title' => 'Mobile money & reminders', 'icon' => 'fa-mobile', 'uri' => 'engagement', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (DB::table('admin_roles')->exists()) {
            \App\Support\AdminAccess::ensureShopRoles();
        }
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'engagement')->delete();
    }
};

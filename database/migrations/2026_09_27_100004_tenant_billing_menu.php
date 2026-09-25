<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** P3-6: "My plan & billing" in every tenant's menu; re-scope role menus (owners keep every menu). Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('admin_menu')->where('uri', 'billing')->exists()) {
            $order = (int) DB::table('admin_menu')->where('parent_id', 0)->max('order');
            DB::table('admin_menu')->insert(['parent_id' => 0, 'order' => $order + 1, 'title' => 'My plan & billing', 'icon' => 'fa-credit-card', 'uri' => 'billing', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (DB::table('admin_roles')->exists()) {
            AdminAccess::scopeTenantRoles();
            AdminAccess::ensureShopRoles();
        }
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'billing')->delete();
    }
};

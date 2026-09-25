<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** P4-6: "Your data" (export / delete) in the tenant menu, next to billing. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('admin_menu')->where('uri', 'your-data')->exists()) {
            DB::table('admin_menu')->insert(['parent_id' => 0, 'order' => (int) DB::table('admin_menu')->where('parent_id', 0)->max('order') + 1,
                'title' => 'Your data', 'icon' => 'fa-shield', 'uri' => 'your-data', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (DB::table('admin_roles')->exists()) {
            \App\Support\AdminAccess::scopeTenantRoles();
            \App\Support\AdminAccess::ensureShopRoles();
        }
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'your-data')->delete();
    }
};

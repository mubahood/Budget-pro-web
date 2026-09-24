<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P0-12: remove the Inventory Forecasts / Auto Reorder Rules menu entries and drop
 * their paths from the tenant allow-list. The modules return in Phase 4 behind
 * saas.features.inventory_automation.
 */
return new class extends Migration
{
    private const URIS = ['inventory-forecasts', 'auto-reorder-rules'];

    public function up(): void
    {
        $ids = DB::table('admin_menu')->whereIn('uri', self::URIS)->pluck('id');
        DB::table('admin_role_menu')->whereIn('menu_id', $ids)->delete();
        DB::table('admin_menu')->whereIn('id', $ids)->delete();

        if (DB::table('admin_roles')->exists()) {
            AdminAccess::scopeTenantRoles();
        }
    }

    public function down(): void
    {
        $order = (int) DB::table('admin_menu')->max('order');
        foreach ([['Inventory Forecasts', 'inventory-forecasts', 'fa-line-chart'], ['Auto Reorder Rules', 'auto-reorder-rules', 'fa-refresh']] as [$title, $uri, $icon]) {
            if (! DB::table('admin_menu')->where('uri', $uri)->exists()) {
                DB::table('admin_menu')->insert(['parent_id' => 0, 'order' => ++$order, 'title' => $title, 'icon' => $icon, 'uri' => $uri, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
};

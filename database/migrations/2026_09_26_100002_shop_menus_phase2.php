<?php

use App\Support\AdminAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P2-10 / plan A9: plain-language shop menu (Products, Sales / POS, Customers,
 * Suppliers, Receive stock, Stock counts, Shifts, Units) and tenant access to
 * the new screens. Idempotent.
 */
return new class extends Migration
{
    private const RENAMES = [
        'stock-items' => 'Products', 'stock-categories' => 'Categories', 'stock-sub-categories' => 'Sub-categories',
        'stock-records' => 'Stock movements', 'sale-records' => 'Sales / POS', 'financial-records' => 'Income & expenses',
        'financial-categories' => 'Finance categories', 'financial-periods' => 'Financial periods', 'employees' => 'Team',
    ];

    private const NEW = [
        ['Customers', 'customers', 'fa-users'], ['Suppliers', 'suppliers', 'fa-truck'], ['Receive stock', 'goods-receipts', 'fa-download'],
        ['Stock counts', 'stock-takes', 'fa-list-ol'], ['Shifts & cash-up', 'shifts', 'fa-clock-o'], ['Units', 'units', 'fa-balance-scale'],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $uri => $title) {
            DB::table('admin_menu')->where('uri', $uri)->update(['title' => $title]);
        }
        $shop = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Shop Management System')->value('id');
        if ($shop) {
            DB::table('admin_menu')->where('id', $shop)->update(['title' => 'Shop', 'icon' => 'fa-shopping-cart']);
        } else {
            $shop = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Shop')->value('id')
                ?? DB::table('admin_menu')->insertGetId(['parent_id' => 0, 'order' => 2, 'title' => 'Shop', 'icon' => 'fa-shopping-cart', 'uri' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
        $order = (int) DB::table('admin_menu')->where('parent_id', $shop)->max('order');
        foreach (self::NEW as [$title, $uri, $icon]) {
            if (! DB::table('admin_menu')->where('uri', $uri)->exists()) {
                DB::table('admin_menu')->insert(['parent_id' => $shop, 'order' => ++$order, 'title' => $title, 'icon' => $icon, 'uri' => $uri, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if (DB::table('admin_roles')->exists()) {
            AdminAccess::scopeTenantRoles();
        }
    }

    public function down(): void
    {
        DB::table('admin_menu')->whereIn('uri', array_column(self::NEW, 1))->delete();
    }
};

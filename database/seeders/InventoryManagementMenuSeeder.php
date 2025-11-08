<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryManagementMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Find the highest order number to append new items
        $maxOrder = DB::table('admin_menu')->max('order') ?? 0;
        
        // Check if "Inventory Management" parent menu exists
        $inventoryParent = DB::table('admin_menu')
            ->where('title', 'Inventory Management')
            ->first();
        
        if (!$inventoryParent) {
            // Create parent menu
            $parentId = DB::table('admin_menu')->insertGetId([
                'parent_id' => 0,
                'order' => $maxOrder + 1,
                'title' => 'Inventory Management',
                'icon' => 'fa-boxes',
                'uri' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $maxOrder++;
        } else {
            $parentId = $inventoryParent->id;
        }
        
        // Add Purchase Orders menu if it doesn't exist
        $purchaseOrdersExists = DB::table('admin_menu')
            ->where('uri', 'purchase-orders')
            ->exists();
            
        if (!$purchaseOrdersExists) {
            DB::table('admin_menu')->insert([
                'parent_id' => $parentId,
                'order' => $maxOrder + 1,
                'title' => 'Purchase Orders',
                'icon' => 'fa-file-invoice',
                'uri' => 'purchase-orders',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $maxOrder++;
        }
        
        // Add Inventory Forecasts menu if it doesn't exist
        $forecastsExists = DB::table('admin_menu')
            ->where('uri', 'inventory-forecasts')
            ->exists();
            
        if (!$forecastsExists) {
            DB::table('admin_menu')->insert([
                'parent_id' => $parentId,
                'order' => $maxOrder + 1,
                'title' => 'Inventory Forecasts',
                'icon' => 'fa-chart-line',
                'uri' => 'inventory-forecasts',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $maxOrder++;
        }
        
        // Add Auto Reorder Rules menu if it doesn't exist
        $reorderRulesExists = DB::table('admin_menu')
            ->where('uri', 'auto-reorder-rules')
            ->exists();
            
        if (!$reorderRulesExists) {
            DB::table('admin_menu')->insert([
                'parent_id' => $parentId,
                'order' => $maxOrder + 1,
                'title' => 'Auto Reorder Rules',
                'icon' => 'fa-sync-alt',
                'uri' => 'auto-reorder-rules',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $maxOrder++;
        }
        
        $this->command->info('Inventory management menu items added successfully!');
    }
}

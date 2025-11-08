<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SaleRecordsMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Find the highest order number to append new items
        $maxOrder = DB::table('admin_menu')->max('order') ?? 0;
        
        // Check if "Sales" parent menu exists
        $salesParent = DB::table('admin_menu')
            ->where('title', 'Sales')
            ->first();
        
        if (!$salesParent) {
            // Create parent menu
            $parentId = DB::table('admin_menu')->insertGetId([
                'parent_id' => 0,
                'order' => $maxOrder + 1,
                'title' => 'Sales',
                'icon' => 'fa-shopping-cart',
                'uri' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $maxOrder++;
            $this->command->info('Sales parent menu created!');
        } else {
            $parentId = $salesParent->id;
            $this->command->info('Sales parent menu already exists.');
        }
        
        // Add Sale Records menu if it doesn't exist
        $saleRecordsExists = DB::table('admin_menu')
            ->where('uri', 'sale-records')
            ->exists();
            
        if (!$saleRecordsExists) {
            DB::table('admin_menu')->insert([
                'parent_id' => $parentId,
                'order' => $maxOrder + 1,
                'title' => 'Sale Records',
                'icon' => 'fa-receipt',
                'uri' => 'sale-records',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->command->info('Sale Records menu item added successfully!');
        } else {
            $this->command->info('Sale Records menu item already exists.');
        }
    }
}

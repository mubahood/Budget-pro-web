<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Template packs (plan C3) from database/data/product_templates.php — idempotent. */
class ProductTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('data/product_templates.php');
        foreach ($data['packs'] as $type => $rows) {
            foreach ($rows as $i => [$name, $cat, $sub, $unit, $sell, $cost]) {
                DB::table('product_templates')->updateOrInsert(
                    ['business_type' => $type, 'country' => 'UG', 'name' => $name],
                    ['pack_version' => $data['version'], 'category' => $cat, 'sub_category' => $sub, 'unit' => $unit, 'sort_order' => $i,
                        'prices' => json_encode(['UGX' => ['sell' => $sell, 'cost' => $cost]]), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }
}

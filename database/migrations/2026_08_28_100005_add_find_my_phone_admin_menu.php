<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'tracked-devices')->exists()) {
            return;
        }

        $now = now();
        $maxOrder = (int) DB::table('admin_menu')->max('order');

        $parentId = DB::table('admin_menu')->insertGetId([
            'parent_id' => 0,
            'order' => $maxOrder + 1,
            'title' => 'Find My Phone',
            'icon' => 'fa-map-marker',
            'uri' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('admin_menu')->insert([
            'parent_id' => $parentId,
            'order' => $maxOrder + 2,
            'title' => 'Devices',
            'icon' => 'fa-mobile',
            'uri' => 'tracked-devices',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $parent = DB::table('admin_menu')->where('title', 'Find My Phone')->where('parent_id', 0)->first();
        DB::table('admin_menu')->where('uri', 'tracked-devices')->delete();
        if ($parent) {
            DB::table('admin_menu')->where('id', $parent->id)->delete();
        }
    }
};

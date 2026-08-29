<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('admin_menu')->where('uri', 'device-locations')->exists()) {
            return;
        }

        $parent = DB::table('admin_menu')->where('title', 'Find My Phone')->where('parent_id', 0)->first();
        if (! $parent) {
            return;
        }

        $now = now();
        $maxOrder = (int) DB::table('admin_menu')->max('order');

        $rows = [
            ['title' => 'Fleet Map', 'icon' => 'fa-globe', 'uri' => 'tracking-map'],
            ['title' => 'Location History', 'icon' => 'fa-history', 'uri' => 'device-locations'],
            ['title' => 'Remote Commands', 'icon' => 'fa-terminal', 'uri' => 'device-commands'],
        ];

        foreach ($rows as $i => $row) {
            DB::table('admin_menu')->insert([
                'parent_id' => $parent->id,
                'order' => $maxOrder + 1 + $i,
                'title' => $row['title'],
                'icon' => $row['icon'],
                'uri' => $row['uri'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('admin_menu')->whereIn('uri', ['tracking-map', 'device-locations', 'device-commands'])->delete();
    }
};

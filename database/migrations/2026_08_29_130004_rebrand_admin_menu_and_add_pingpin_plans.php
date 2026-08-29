<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The admin sidebar still says "Find My Phone" — the app itself was
 * rebranded to PingPin in the client this session (Task 0.3). Also adds
 * "Plans" for the new PingPinPlanController (Task 2.5) under the same
 * parent, matching every other module's menu-migration pattern this session.
 */
return new class extends Migration
{
    public function up(): void
    {
        $parent = DB::table('admin_menu')->where('title', 'Find My Phone')->where('parent_id', 0)->first();
        if (! $parent) {
            return;
        }

        DB::table('admin_menu')->where('id', $parent->id)->update(['title' => 'Ping Pin', 'updated_at' => now()]);

        if (DB::table('admin_menu')->where('uri', 'pingpin-plans')->exists()) {
            return;
        }

        $maxOrder = (int) DB::table('admin_menu')->max('order');
        DB::table('admin_menu')->insert([
            'parent_id' => $parent->id,
            'order' => $maxOrder + 1,
            'title' => 'Plans',
            'icon' => 'fa-money',
            'uri' => 'pingpin-plans',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_menu')->where('uri', 'pingpin-plans')->delete();
        DB::table('admin_menu')->where('title', 'Ping Pin')->where('parent_id', 0)->update(['title' => 'Find My Phone']);
    }
};

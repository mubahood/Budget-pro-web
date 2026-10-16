<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| A lifetime plan ends 100 years ahead (Company::activateSubscription), which a MySQL TIMESTAMP
| cannot hold (it stops at 2038-01-19), so the standalone edition's lifetime plan
| (docs/STANDALONE_EDITION_PLAN.md §7.1) failed to save. DATETIME holds it. Values keep their
| meaning: Laravel writes and reads the same wall-clock text either way, in the same session.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // SQLite has no such range limit
        }
        DB::statement('ALTER TABLE subscriptions MODIFY ends_at DATETIME NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::table('subscriptions')->where('ends_at', '>', '2038-01-01 00:00:00')->update(['ends_at' => '2037-12-31 23:59:59']);
        DB::statement('ALTER TABLE subscriptions MODIFY ends_at TIMESTAMP NULL');
    }
};

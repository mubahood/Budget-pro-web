<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * P4-5 (plan Part D): reconcile a column created by hand before migrations
 * tracked it, then add the foreign keys, per-company uniques and status checks
 * the data already satisfies. Anything blocked by dirty data is logged and shown
 * by `php artisan schema:integrity`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budget_items') && ! Schema::hasColumn('budget_items', 'priority')) {
            Schema::table('budget_items', fn (Blueprint $t) => $t->string('priority')->nullable()->default('Medium'));
        }
        $shop = \Illuminate\Support\Facades\DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Shop')->value('id');
        if ($shop && ! \Illuminate\Support\Facades\DB::table('admin_menu')->where('uri', 'duplicates')->exists()) {
            \Illuminate\Support\Facades\DB::table('admin_menu')->insert(['parent_id' => $shop, 'order' => (int) \Illuminate\Support\Facades\DB::table('admin_menu')->where('parent_id', $shop)->max('order') + 1,
                'title' => 'Possible duplicates', 'icon' => 'fa-clone', 'uri' => 'duplicates', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (\Illuminate\Support\Facades\DB::table('admin_roles')->exists()) {
            \App\Support\AdminAccess::ensureShopRoles();
        }
        foreach (SchemaIntegrity::run(true) as $r) {
            if ($r['status'] === 'skipped') {
                Log::warning('[schema:integrity] '.$r['kind'].' '.$r['target'].' skipped: '.($r['detail'] ?? ''));
            }
        }
    }

    public function down(): void
    {
        // Constraints are kept on rollback; they only encode rules the data already met.
    }
};

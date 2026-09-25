<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-5 / P3-6: once-only scheduled notices, local-currency prices for mobile
 * money (KES/TZS/RWF), and the public Free tier that trials fall back to (H2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_notices')) {
            Schema::create('scheduled_notices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('key', 60);
                $t->string('period', 40); // e.g. 2026-09-27, device:12:2026-09-27, trial:3
                $t->timestamp('created_at')->nullable();
                $t->unique(['company_id', 'key', 'period'], 'notice_once');
            });
        }
        Schema::table('plans', function (Blueprint $t) {
            if (! Schema::hasColumn('plans', 'prices')) {
                $t->json('prices')->nullable()->after('price_ugx'); // {"KES": 1500, "TZS": 30000, "RWF": 15000}
            }
        });

        if (! DB::table('plans')->where('slug', 'free')->exists()) {
            DB::table('plans')->insert([
                'name' => 'Free', 'slug' => 'free', 'description' => 'For small shops getting started: one phone, 100 products, 200 sales a month.',
                'price' => 0, 'price_ugx' => 0, 'currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_active' => true, 'is_public' => true, 'sort_order' => 0,
                'features' => json_encode(['whatsapp_receipts' => false, 'whatsapp_automation' => false, 'multi_location' => false, 'forecasting' => false, 'auto_reorder' => false, 'api_access' => false]),
                'limits' => json_encode(['max_devices' => 1, 'max_users' => 2, 'max_products' => 100, 'max_sales_per_month' => 200, 'max_locations' => 1, 'storage_mb' => 100]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_notices');
        if (Schema::hasColumn('plans', 'prices')) {
            Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('prices'));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** P3-2 / P3-8: template packs as server data, and the wizard's money settings on the company. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_templates')) {
            Schema::create('product_templates', function (Blueprint $t) {
                $t->id();
                $t->string('business_type', 30);
                $t->string('country', 2)->nullable(); // null = any country
                $t->unsignedInteger('pack_version')->default(1);
                $t->string('name', 150);
                $t->string('category', 100);
                $t->string('sub_category', 100);
                $t->string('unit', 20)->default('pcs');
                $t->json('prices')->nullable();       // {"UGX": {"sell": 5000, "cost": 4400}}
                $t->unsignedInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['business_type', 'country', 'name'], 'template_unique');
            });
        }
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'payment_methods')) {
                $t->json('payment_methods')->nullable();
            }
            if (! Schema::hasColumn('companies', 'receipt_channels')) {
                $t->json('receipt_channels')->nullable();
            }
            if (! Schema::hasColumn('companies', 'tax_rate')) {
                $t->decimal('tax_rate', 5, 2)->nullable();
            }
        });

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

        // Platform admins manage template packs.
        if (! DB::table('admin_menu')->where('uri', 'product-templates')->exists()) {
            $admin = DB::table('admin_menu')->where('parent_id', 0)->where('title', 'Admin')->value('id');
            if ($admin) {
                DB::table('admin_menu')->insert(['parent_id' => $admin, 'order' => (int) DB::table('admin_menu')->where('parent_id', $admin)->max('order') + 1,
                    'title' => 'Product templates', 'icon' => 'fa-clone', 'uri' => 'product-templates', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_templates');
        DB::table('admin_menu')->where('uri', 'product-templates')->delete();
    }
};

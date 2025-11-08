<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('created_by_rule_id')->nullable()->after('notes')->constrained('auto_reorder_rules')->nullOnDelete();
            $table->boolean('auto_generated')->default(false)->after('created_by_rule_id');
            $table->date('order_date')->nullable()->after('po_date'); // Alias for po_date for compatibility
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['created_by_rule_id']);
            $table->dropColumn(['created_by_rule_id', 'auto_generated', 'order_date']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan C4 (consent and messages): a customer agrees to offers and news
 * (marketing_opt_in) or asks for no messages at all (messages_opt_out). Additive; both off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            if (! Schema::hasColumn('customers', 'marketing_opt_in')) {
                $t->boolean('marketing_opt_in')->default(false);
            }
            if (! Schema::hasColumn('customers', 'marketing_opt_in_at')) {
                $t->timestamp('marketing_opt_in_at')->nullable();
            }
            if (! Schema::hasColumn('customers', 'messages_opt_out')) {
                $t->boolean('messages_opt_out')->default(false);
            }
            if (! Schema::hasColumn('customers', 'messages_opt_out_at')) {
                $t->timestamp('messages_opt_out_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            foreach (['marketing_opt_in', 'marketing_opt_in_at', 'messages_opt_out', 'messages_opt_out_at'] as $c) {
                if (Schema::hasColumn('customers', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};

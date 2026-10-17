<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The small "Powered by Budget Pro · WhatsApp …" line on a business's receipts and invoices
| (docs/roadmap/06 §4, App\Support\SupportContact::receiptLineOn). Null = the edition's default
| (config support.receipt_line_default: on for the SaaS, off for a standalone copy); true or false =
| the owner's own choice in Settings › Receipts.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'receipt_support_line')) {
            return;
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('receipt_support_line')->nullable()->after('receipt_footer');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'receipt_support_line')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('receipt_support_line');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supermarket plan F2 (budget-pro-new/docs/SUPERMARKET_PLAN.md): fiscal receipts / e-invoicing.
 *  - `fiscal_settings`: one row per shop: the adapter (manual, efris…), its config (encrypted with Laravel Crypt:
 *    TIN, device number, private key…), sandbox|production and whether it is switched on;
 *  - `fiscal_submissions`: the queue and the references per sale (and per return, for credit notes);
 *  - customers.tin: the buyer's tax number, printed on / sent with a fiscal invoice when known.
 * Additive only: nothing reads these unless the shop has the `fiscal` feature on and an adapter configured.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_settings')) {
            Schema::create('fiscal_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('adapter', 30)->nullable();       // FiscalRegistry key: manual | efris
                $t->longText('config')->nullable();          // Crypt::encryptString(json), never sent to the browser
                $t->string('environment', 12)->default('sandbox'); // sandbox | production
                $t->boolean('is_active')->default(false);
                $t->timestamp('last_tested_at')->nullable();
                $t->boolean('last_test_ok')->nullable();
                $t->string('last_test_message', 500)->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique('company_id', 'fiscal_settings_company_unique');
            });
        }

        if (! Schema::hasTable('fiscal_submissions')) {
            Schema::create('fiscal_submissions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('sale_record_id');
                $t->unsignedBigInteger('sale_return_id')->nullable(); // set = a credit note for that return
                $t->string('adapter', 30);
                $t->string('status', 10)->default('pending'); // pending | sent | failed | skipped
                $t->unsignedInteger('attempts')->default(0);
                $t->timestamp('next_attempt_at')->nullable();
                $t->timestamp('last_attempt_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->string('fiscal_number', 100)->nullable();
                $t->string('verification_code', 100)->nullable();
                $t->text('qr_payload')->nullable();
                $t->string('error', 1000)->nullable();
                $t->mediumText('raw_request')->nullable();  // trimmed, never with keys or signatures
                $t->mediumText('raw_response')->nullable(); // trimmed
                $t->boolean('owner_notified')->default(false);
                $t->unsignedBigInteger('entered_by')->nullable(); // manual adapter: who typed the number
                $t->timestamps();
                $t->index(['status', 'next_attempt_at'], 'fiscal_submissions_due_idx');
                $t->index(['company_id', 'sale_record_id'], 'fiscal_submissions_sale_idx');
                $t->index(['company_id', 'status', 'id'], 'fiscal_submissions_company_status_idx');
            });
        }

        if (! Schema::hasColumn('customers', 'tin')) {
            Schema::table('customers', fn (Blueprint $t) => $t->string('tin', 30)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'tin')) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('tin'));
        }
        Schema::dropIfExists('fiscal_submissions');
        Schema::dropIfExists('fiscal_settings');
    }
};

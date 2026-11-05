<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing a month (LC-02): the day the account's books are locked through, one row per close with its totals; and the statements a close
 * issues (LC-03), frozen, numbered and read from here ever after. Additive and guarded: nothing reads a row until a month is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_settings', 'closed_through')) {
            Schema::table('rental_settings', function (Blueprint $t) {
                $t->date('closed_through')->nullable();
            });
        }
        if (! Schema::hasTable('rental_closes')) {
            Schema::create('rental_closes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->date('month'); // its first day
                $t->unsignedInteger('version');
                $t->string('status', 10); // closed | reopened
                $t->timestamp('closed_at')->nullable();
                $t->unsignedBigInteger('closed_by_id')->nullable();
                $t->timestamp('reopened_at')->nullable();
                $t->unsignedBigInteger('reopened_by_id')->nullable();
                $t->string('reopen_reason', 255)->nullable();
                $t->json('totals');
                $t->char('proof', 64);
                $t->timestamps();
                $t->unique(['company_id', 'month', 'version'], 'rental_closes_month_unique');
                $t->index(['company_id', 'status', 'month'], 'rental_closes_status_idx');
            });
        }
        if (! Schema::hasTable('rental_statements')) {
            Schema::create('rental_statements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('landlord_id');
                $t->string('number', 30);
                $t->date('month'); // its first day
                $t->unsignedInteger('version');
                $t->unsignedBigInteger('replaces_id')->nullable();
                $t->unsignedBigInteger('replaced_by_id')->nullable();
                $t->unsignedBigInteger('close_id');
                $t->string('status', 10); // issued | replaced
                $t->bigInteger('opening_minor');
                $t->bigInteger('collected_minor');
                $t->bigInteger('commission_minor');
                $t->bigInteger('expenses_minor');
                $t->bigInteger('payouts_minor');
                $t->bigInteger('deposits_minor');
                $t->bigInteger('other_minor');
                $t->bigInteger('closing_minor');
                $t->json('body');
                $t->char('proof', 64);
                $t->timestamp('issued_at')->nullable();
                $t->unsignedBigInteger('issued_by_id')->nullable();
                $t->timestamp('prepared_at')->nullable();
                $t->string('prepared_via', 10)->nullable(); // whatsapp | sms | link
                $t->unsignedBigInteger('prepared_by_id')->nullable();
                $t->timestamp('first_opened_at')->nullable();
                $t->unsignedInteger('open_count')->default(0);
                $t->timestamps();
                $t->unique(['company_id', 'number'], 'rental_statements_number_unique');
                $t->unique(['company_id', 'landlord_id', 'month', 'version'], 'rental_statements_version_unique');
                $t->index(['company_id', 'month', 'status'], 'rental_statements_month_idx');
            });
        }
    }

    public function down(): void
    {
        // Additive and live: nothing to take back.
    }
};

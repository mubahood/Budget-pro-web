<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger's simple flows (docs/akiba/flows-plan.md):
 * - sacco_account_categories / sacco_account_entries: the SACCO's own money in and out (bank charges, office expenses,
 *   a grant, other income), each entry posted once; a mistake is a reversing entry, never an edit;
 * - sacco_shareouts / sacco_shareout_lines: a share-out and one line per member, locked once posted;
 * - sacco_loan_steps: who did what to a loan request, when, with their note;
 * - sacco_member_notices: what a member sees in their app or web page ("a loan request waits for you");
 * - sacco_loans.status widened for the simple statuses (pending_guarantor, declined_by_guarantor, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_account_categories')) {
            Schema::create('sacco_account_categories', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('direction', 3); // in | out
                $t->string('name', 80);
                $t->unsignedBigInteger('ledger_account_id');
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamps();
                $t->index(['company_id', 'direction'], 'sacco_account_categories_idx');
            });
        }

        if (! Schema::hasTable('sacco_account_entries')) {
            Schema::create('sacco_account_entries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('uuid', 36);
                $t->string('direction', 3); // in | out
                $t->unsignedBigInteger('category_id');
                $t->string('category_name', 80);
                $t->unsignedBigInteger('amount_minor');
                $t->char('currency', 3);
                $t->date('value_date');
                $t->string('method', 12); // cash | bank | mobile_money
                $t->string('notes', 255)->nullable();
                $t->string('status', 10)->default('posted'); // posted | reversed
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->unsignedBigInteger('reversal_entry_id')->nullable();
                $t->string('reversal_reason', 255)->nullable();
                $t->unsignedBigInteger('reversed_by_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->unsignedBigInteger('recorded_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'uuid'], 'sacco_account_entries_uuid_unique');
                $t->index(['company_id', 'value_date'], 'sacco_account_entries_date_idx');
            });
        }

        if (! Schema::hasTable('sacco_shareouts')) {
            Schema::create('sacco_shareouts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('uuid', 36);
                $t->date('share_on');
                $t->string('method', 12); // cash | bank | mobile_money
                $t->unsignedBigInteger('amount_minor');
                $t->unsignedBigInteger('savings_minor'); // savings paid back
                $t->unsignedBigInteger('profit_minor'); // profit shared
                $t->unsignedBigInteger('savings_total_minor'); // members' savings at the run
                $t->bigInteger('profit_available_minor');
                $t->unsignedBigInteger('total_shares');
                $t->char('currency', 3);
                $t->string('status', 10)->default('posted');
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->string('notes', 255)->nullable();
                $t->unsignedBigInteger('posted_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'uuid'], 'sacco_shareouts_uuid_unique');
                $t->unique(['company_id', 'share_on'], 'sacco_shareouts_day_unique');
            });
        }

        if (! Schema::hasTable('sacco_shareout_lines')) {
            Schema::create('sacco_shareout_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('shareout_id');
                $t->unsignedBigInteger('member_id');
                $t->unsignedBigInteger('savings_minor'); // their savings at the run
                $t->unsignedBigInteger('shares');
                $t->unsignedBigInteger('savings_paid_minor');
                $t->unsignedBigInteger('profit_minor');
                $t->unsignedBigInteger('payout_minor');
                $t->timestamps();
                $t->unique(['shareout_id', 'member_id'], 'sacco_shareout_lines_unique');
                $t->index(['company_id', 'member_id'], 'sacco_shareout_lines_member_idx');
            });
        }

        if (! Schema::hasTable('sacco_loan_steps')) {
            Schema::create('sacco_loan_steps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('loan_id');
                $t->string('step', 24); // requested | guarantor_approved | guarantor_declined | chair_approved | chair_declined | paid_out | not_paid_out | repayment | closed
                $t->string('actor_type', 8); // member | user
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->unsignedBigInteger('actor_member_id')->nullable();
                $t->string('actor_name', 150);
                $t->string('note', 500)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'loan_id'], 'sacco_loan_steps_loan_idx');
            });
        }

        if (! Schema::hasTable('sacco_member_notices')) {
            Schema::create('sacco_member_notices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id');
                $t->unsignedBigInteger('loan_id')->nullable();
                $t->string('kind', 30);
                $t->string('title', 150);
                $t->string('body', 500);
                $t->timestamp('read_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'member_id', 'read_at'], 'sacco_member_notices_idx');
            });
        }

        if (Schema::hasTable('sacco_loans')) {
            DB::statement('ALTER TABLE `sacco_loans` MODIFY `status` VARCHAR(24) NOT NULL DEFAULT \'draft\'');
            if (SchemaIntegrity::checksSupported()) {
                $has = DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', 'sacco_loans')
                    ->where('constraint_name', 'chk_sacco_loans_status')->exists();
                if ($has) {
                    DB::statement('ALTER TABLE `sacco_loans` DROP CHECK `chk_sacco_loans_status`');
                }
            }
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        foreach (['sacco_member_notices', 'sacco_loan_steps', 'sacco_shareout_lines', 'sacco_shareouts', 'sacco_account_entries', 'sacco_account_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

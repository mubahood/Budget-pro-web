<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P3.1 (docs/akiba/04-architecture.md §3.5, §11.1): a SACCO's mobile-money request-to-pay rides on the
 * shops' momo_requests table, with additive columns only. Old rows keep `purpose` null (a shop sale); a SACCO's rows
 * name what the money is for (`sacco_deposit`, `sacco_repayment`, `sacco_shares`), its subject (the savings account,
 * loan or share account), the member, the amount in whole minor units, and the receipt it became. `amount` stays
 * filled (decimal, major units) for the existing code; Akiba reads `amount_minor` only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('momo_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('momo_requests', 'purpose')) {
                $t->string('purpose', 20)->nullable()->after('company_id'); // null = sale | sacco_deposit | sacco_repayment | sacco_shares
                $t->string('uuid', 36)->nullable()->after('purpose');
                $t->string('subject_type', 40)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->unsignedBigInteger('sacco_member_id')->nullable();
                $t->unsignedBigInteger('amount_minor')->nullable();
                $t->string('requested_via', 10)->nullable(); // officer | member
                $t->unsignedBigInteger('sacco_transaction_id')->nullable();
                $t->unsignedInteger('checks')->default(0);
                $t->timestamp('checked_at')->nullable();
                $t->timestamp('settled_at')->nullable();

                $t->unique(['company_id', 'uuid'], 'momo_requests_company_uuid_unique');
                $t->index(['company_id', 'purpose', 'status'], 'momo_requests_purpose_status_idx');
                $t->index(['company_id', 'sacco_member_id'], 'momo_requests_sacco_member_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('momo_requests', function (Blueprint $t) {
            if (Schema::hasColumn('momo_requests', 'purpose')) {
                $t->dropUnique('momo_requests_company_uuid_unique');
                $t->dropIndex('momo_requests_purpose_status_idx');
                $t->dropIndex('momo_requests_sacco_member_idx');
                $t->dropColumn(['purpose', 'uuid', 'subject_type', 'subject_id', 'sacco_member_id', 'amount_minor', 'requested_via', 'sacco_transaction_id', 'checks', 'checked_at', 'settled_at']);
            }
        });
    }
};

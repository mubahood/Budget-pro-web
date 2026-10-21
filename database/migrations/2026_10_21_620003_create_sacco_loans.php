<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P2.4 (docs/akiba/04-architecture.md §3.4, §5.3, ADR 0009 §7): loans and what hangs on them.
 *
 * - sacco_loans: the workflow (status) and the terms snapshot. The money is the ledger's: principal, interest,
 *   penalty and fees outstanding are caches of the loan's lines on 1300/1320/1330/1340, written only by
 *   PostingService through the loan SubLedger. Interest and penalty in suspense (substandard and worse, SI 51 reg.
 *   41) are memorandum figures, never ledger. A phone may start a draft (sync columns); everything after is online.
 * - schedule lines per version (a reschedule supersedes the old lines and keeps them); the paid columns are
 *   allocation caches written by Repayments in the posting transaction.
 * - guarantors (consent, the lien on their savings), collateral, committee votes (one per officer per round),
 *   documents (what was signed is what was shown), repayments (the allocation behind each repayment entry) and
 *   changes (reschedules, top-ups, write-offs, recoveries: the history a loan keeps).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_loans')) {
            Schema::create('sacco_loans', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('loan_no', 24)->nullable(); // LN-2026-000123, given on submission (a phone's draft has none)
                $t->unsignedBigInteger('member_id');
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('officer_id')->nullable(); // admin_users.id: the loans officer handling it
                $t->string('status', 16)->default('draft');
                $t->string('source', 8)->default('staff'); // staff | member | phone
                $t->unsignedBigInteger('applied_minor');
                $t->unsignedBigInteger('approved_minor')->nullable();
                $t->char('currency', 3);
                $t->unsignedSmallInteger('term'); // instalments asked for (approved: terms.term)
                $t->string('purpose', 255)->nullable();
                $t->string('purpose_category', 40)->nullable();
                $t->unsignedInteger('inputs_share_bp')->nullable(); // PDM: share spent on inputs
                $t->json('terms'); // the product's terms when applied for (LoanTerms)
                $t->json('appraisal')->nullable(); // Eligibility's findings and the officer's notes
                $t->boolean('is_insider')->default(false); // copied at submission: goes to the committee, with recusal
                $t->string('disbursement_channel', 10)->nullable(); // savings | cash | bank | momo
                $t->unsignedBigInteger('disburse_to_savings_account_id')->nullable();
                $t->string('momo_phone_e164', 20)->nullable();
                $t->string('bank_reference', 80)->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->unsignedBigInteger('appraised_by_id')->nullable();
                $t->timestamp('appraised_at')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamp('rejected_at')->nullable();
                $t->string('decision_note', 255)->nullable();
                $t->string('appeal_reason', 255)->nullable();
                $t->unsignedBigInteger('decision_approval_id')->nullable();
                $t->timestamp('signed_at')->nullable();
                $t->date('signed_on')->nullable();
                $t->string('witness_name', 150)->nullable();
                $t->string('witness_phone_e164', 20)->nullable();
                $t->date('cooling_off_ends_on')->nullable();
                $t->unsignedBigInteger('disbursement_approval_id')->nullable();
                $t->unsignedBigInteger('disbursement_entry_id')->nullable();
                $t->unsignedBigInteger('payout_id')->nullable();
                $t->date('disbursed_on')->nullable();
                $t->date('first_due_on')->nullable();
                $t->date('maturity_on')->nullable();
                $t->date('closed_on')->nullable();
                $t->date('cancelled_on')->nullable();
                $t->date('written_off_on')->nullable();
                // Ledger caches (PostingService only, through the loan SubLedger).
                $t->bigInteger('principal_outstanding_minor')->default(0);
                $t->bigInteger('interest_due_minor')->default(0);
                $t->bigInteger('penalty_due_minor')->default(0);
                $t->bigInteger('fees_due_minor')->default(0);
                // Memorandum (never ledger): charged but not recognised while non-performing.
                $t->unsignedBigInteger('interest_in_suspense_minor')->default(0);
                $t->unsignedBigInteger('penalty_in_suspense_minor')->default(0);
                // The daily arrears run.
                $t->unsignedBigInteger('arrears_principal_minor')->default(0);
                $t->unsignedBigInteger('arrears_interest_minor')->default(0);
                $t->unsignedInteger('days_past_due')->default(0);
                $t->unsignedSmallInteger('instalments_in_arrears')->default(0);
                $t->string('classification', 12)->default('performing');
                $t->date('arrears_on')->nullable(); // the day the arrears figures are for
                $t->date('npl_since')->nullable();
                $t->unsignedBigInteger('npl_cap_minor')->nullable(); // SI 108 reg. 7: interest + fees after NPL ≤ principal owing then
                $t->unsignedBigInteger('npl_charged_minor')->default(0);
                $t->unsignedSmallInteger('good_instalments')->default(0); // paid on time since the loan became non-performing
                $t->unsignedTinyInteger('reschedule_count')->default(0);
                $t->unsignedTinyInteger('upgrade_count')->default(0);
                $t->unsignedBigInteger('top_up_of_loan_id')->nullable();
                $t->unsignedBigInteger('topped_up_by_loan_id')->nullable();
                $t->unsignedSmallInteger('schedule_version')->default(1);
                $t->string('penalty_through', 7)->nullable(); // YYYY-MM: the last month penalty was charged for
                $t->unsignedBigInteger('written_off_minor')->default(0);
                $t->unsignedBigInteger('recovered_minor')->default(0);
                $t->unsignedBigInteger('member_actor_id')->nullable(); // applied by the member (self-service)
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();
                $t->string('uuid', 36);
                $t->unsignedBigInteger('server_seq')->default(0);
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_deleted')->default(false);
                $t->unsignedBigInteger('client_created_at')->nullable();
                $t->unsignedBigInteger('client_updated_at')->nullable();
                $t->string('device_id', 64)->nullable();

                $t->unique(['company_id', 'uuid'], 'sacco_loans_uuid_unique');
                $t->unique(['company_id', 'loan_no'], 'sacco_loans_no_unique');
                $t->index(['company_id', 'member_id', 'status'], 'sacco_loans_member_idx');
                $t->index(['company_id', 'status', 'classification'], 'sacco_loans_status_idx');
                $t->index(['company_id', 'product_id'], 'sacco_loans_product_idx');
                $t->index(['company_id', 'server_seq'], 'sacco_loans_seq_idx');
            });
        }

        if (! Schema::hasTable('sacco_loan_schedule_lines')) {
            Schema::create('sacco_loan_schedule_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('loan_id');
                $t->unsignedSmallInteger('schedule_version');
                $t->unsignedSmallInteger('installment_no');
                $t->date('due_on');
                $t->unsignedBigInteger('opening_principal_minor');
                $t->unsignedBigInteger('principal_due_minor');
                $t->unsignedBigInteger('interest_due_minor'); // as scheduled
                $t->unsignedBigInteger('interest_charged_minor')->nullable(); // as charged on the due date (actual outstanding, AK-39)
                $t->boolean('interest_suspended')->default(false); // charged into suspense, not income
                $t->unsignedBigInteger('fee_due_minor')->default(0);
                $t->unsignedBigInteger('principal_paid_minor')->default(0);
                $t->unsignedBigInteger('interest_paid_minor')->default(0);
                $t->unsignedBigInteger('fee_paid_minor')->default(0);
                $t->string('status', 10)->default('due'); // due | partial | paid | superseded
                $t->date('paid_on')->nullable();
                $t->unsignedBigInteger('charge_entry_id')->nullable();
                $t->timestamps();

                $t->unique(['loan_id', 'schedule_version', 'installment_no'], 'sacco_loan_schedule_lines_unique');
                $t->index(['company_id', 'due_on', 'status'], 'sacco_loan_schedule_lines_due_idx');
            });
        }

        if (! Schema::hasTable('sacco_loan_guarantors')) {
            Schema::create('sacco_loan_guarantors', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('loan_id');
                $t->unsignedBigInteger('guarantor_member_id');
                $t->unsignedBigInteger('guaranteed_minor');
                $t->string('consent_status', 10)->default('requested'); // requested | consented | declined | released
                $t->string('consent_channel', 12)->nullable(); // signed_form | sms_link | member_app
                $t->timestamp('notice_sent_at')->nullable(); // the written notice of the amount and nature of the liability
                $t->char('consent_token_hash', 64)->nullable(); // the signed link's opaque token, hashed
                $t->timestamp('token_expires_at')->nullable();
                $t->timestamp('consented_at')->nullable();
                $t->timestamp('declined_at')->nullable();
                $t->unsignedBigInteger('consent_evidence_file_id')->nullable();
                $t->string('consent_otp_ref', 64)->nullable();
                $t->unsignedBigInteger('hold_savings_account_id')->nullable(); // the lien
                $t->unsignedBigInteger('held_minor')->default(0);
                $t->unsignedBigInteger('recovered_minor')->default(0); // taken from their savings on default
                $t->timestamp('released_at')->nullable();
                $t->unsignedBigInteger('requested_by_id')->nullable();
                $t->timestamps();

                $t->unique(['loan_id', 'guarantor_member_id'], 'sacco_loan_guarantors_unique');
                $t->index(['company_id', 'guarantor_member_id', 'consent_status'], 'sacco_loan_guarantors_member_idx');
                $t->index(['company_id', 'consent_token_hash'], 'sacco_loan_guarantors_token_idx');
            });
        }

        if (! Schema::hasTable('sacco_loan_collateral')) {
            Schema::create('sacco_loan_collateral', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('loan_id');
                $t->string('type', 24); // land_title | logbook | chattel | salary_assignment | deposit_lien | other
                $t->string('description', 255);
                $t->unsignedBigInteger('value_minor');
                $t->unsignedBigInteger('document_file_id')->nullable();
                $t->date('demand_notice_on')->nullable(); // SI 108 reg. 8: no sale until 60 days after the written demand
                $t->date('realised_on')->nullable();
                $t->unsignedBigInteger('realised_minor')->nullable();
                $t->date('released_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'loan_id'], 'sacco_loan_collateral_loan_idx');
            });
        }

        if (! Schema::hasTable('sacco_loan_votes')) {
            Schema::create('sacco_loan_votes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('loan_id');
                $t->string('round', 10)->default('committee'); // committee | appeal
                $t->unsignedBigInteger('officer_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('vote', 8); // approve | reject | abstain
                $t->boolean('recused')->default(false);
                $t->string('note', 255)->nullable();
                $t->timestamp('created_at')->nullable();

                $t->unique(['loan_id', 'round', 'officer_id'], 'sacco_loan_votes_unique');
                $t->index(['company_id', 'loan_id'], 'sacco_loan_votes_loan_idx');
            });
        }

        if (! Schema::hasTable('sacco_loan_documents')) {
            Schema::create('sacco_loan_documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('loan_id');
                $t->string('type', 20); // key_facts | contract | signed_contract | witness_statement | demand_notice | stop_letter | consent_form
                $t->unsignedBigInteger('file_id')->nullable();
                $t->string('path', 255)->nullable(); // where the stored PDF is
                $t->char('sha256', 64)->nullable(); // what was shown is what was signed
                $t->unsignedSmallInteger('schedule_version')->default(1);
                $t->timestamp('generated_at')->nullable();
                $t->timestamp('signed_by_member_at')->nullable();
                $t->string('witness_name', 150)->nullable();
                $t->string('witness_phone_e164', 20)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'loan_id', 'type'], 'sacco_loan_documents_loan_idx');
            });
        }

        if (! Schema::hasTable('sacco_loan_repayments')) {
            Schema::create('sacco_loan_repayments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('loan_id');
                $t->string('uuid', 36);
                $t->string('kind', 20); // repayment | guarantor_offset | top_up_settlement | cancellation
                $t->string('channel', 10); // cash | bank | savings | momo | guarantor | internal
                $t->unsignedBigInteger('source_savings_account_id')->nullable();
                $t->unsignedBigInteger('guarantor_id')->nullable(); // sacco_loan_guarantors.id
                $t->unsignedBigInteger('amount_minor');
                $t->unsignedBigInteger('penalty_minor')->default(0);
                $t->unsignedBigInteger('penalty_suspense_minor')->default(0);
                $t->unsignedBigInteger('fees_minor')->default(0);
                $t->unsignedBigInteger('interest_minor')->default(0);
                $t->unsignedBigInteger('interest_suspense_minor')->default(0);
                $t->unsignedBigInteger('interest_to_day_minor')->default(0);
                $t->unsignedBigInteger('principal_minor')->default(0);
                $t->unsignedBigInteger('overpayment_minor')->default(0);
                $t->unsignedBigInteger('overpayment_savings_account_id')->nullable(); // null with an overpayment: 2150
                $t->date('value_date');
                $t->unsignedSmallInteger('schedule_version');
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->unsignedBigInteger('transaction_id')->nullable(); // sacco_transactions (the receipt)
                $t->string('status', 10)->default('posted'); // posted | reversed
                $t->unsignedBigInteger('reversal_entry_id')->nullable();
                $t->string('reference', 80)->nullable();
                $t->string('narration', 255)->nullable();
                $t->unsignedBigInteger('received_by_id')->nullable();
                $t->unsignedBigInteger('member_actor_id')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'uuid'], 'sacco_loan_repayments_uuid_unique');
                $t->index(['company_id', 'loan_id', 'value_date'], 'sacco_loan_repayments_loan_idx');
            });
        }

        if (! Schema::hasTable('sacco_loan_changes')) {
            Schema::create('sacco_loan_changes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('loan_id');
                $t->string('kind', 20); // reschedule | top_up | write_off | recovery | cancellation | upgrade | downgrade | waiver
                $t->unsignedSmallInteger('from_version')->nullable();
                $t->unsignedSmallInteger('to_version')->nullable();
                $t->json('before')->nullable();
                $t->json('after')->nullable();
                $t->unsignedBigInteger('amount_minor')->nullable();
                $t->string('reason', 255)->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('entry_id')->nullable();
                $t->timestamp('borrower_consented_at')->nullable(); // SI 108 reg. 6: no variation without it
                $t->unsignedBigInteger('consent_document_id')->nullable();
                $t->date('effective_on');
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'loan_id', 'kind'], 'sacco_loan_changes_loan_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        foreach (['sacco_loan_changes', 'sacco_loan_repayments', 'sacco_loan_documents', 'sacco_loan_votes', 'sacco_loan_collateral', 'sacco_loan_guarantors', 'sacco_loan_schedule_lines', 'sacco_loans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

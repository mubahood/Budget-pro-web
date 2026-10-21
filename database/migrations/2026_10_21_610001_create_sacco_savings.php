<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.12 (docs/akiba/04-architecture.md §3.3, ADR 0009 §7): savings products with their versioned
 * terms, members' savings accounts (a sub-ledger under each product's 21x0 control account), and sacco_transactions,
 * the money document behind every member-facing posting (deposits, withdrawals, fees, transfers; shares, entrance
 * fees and loan movements use the same table).
 *
 * - Money is BIGINT minor units only (AK-08). balance_minor on an account is a cache written only by PostingService
 *   (through the savings SubLedger), in the posting's own transaction.
 * - Products and accounts carry sync columns so the phone pulls them with real uuids it can name in a receipt
 *   (pull only: a phone never writes them, AK-63). Transactions are an event table (ADR 0001): the phone inserts cash
 *   receipts, nothing else.
 * - A transaction is reversed once (`reverses_id` UNIQUE, the contribution_payments pattern), by a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_savings_products')) {
            Schema::create('sacco_savings_products', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('code', 16);
                $t->string('name', 80);
                $t->string('kind', 12); // voluntary | compulsory | fixed | group
                $t->boolean('withdrawable')->default(true); // compulsory savings: no (non-withdrawable deposits, Form 5)
                $t->unsignedBigInteger('min_opening_minor')->default(0);
                $t->unsignedBigInteger('min_balance_minor')->default(0);
                $t->unsignedInteger('interest_rate_bp')->default(0); // annual; 0 = no interest
                $t->string('interest_basis', 20)->default('none'); // none | daily_balance | min_monthly_balance
                $t->string('interest_posting', 10)->default('annually'); // monthly | quarterly | annually
                $t->unsignedSmallInteger('term_months')->nullable(); // fixed deposits
                $t->unsignedInteger('early_withdrawal_penalty_bp')->default(0);
                $t->unsignedBigInteger('withdrawal_fee_minor')->default(0);
                $t->unsignedSmallInteger('max_withdrawals_per_month')->nullable();
                $t->unsignedSmallInteger('notice_days')->default(0);
                $t->unsignedBigInteger('ledger_account_id'); // the control account (control_of = savings)
                $t->unsignedBigInteger('interest_expense_account_id')->nullable(); // 5100 by default
                $t->text('disclosure_text')->nullable(); // SI 51 reg. 33
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('terms_version')->default(1); // bumped by every terms change (sacco_savings_product_versions)
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

                $t->unique(['company_id', 'code'], 'sacco_savings_products_code_unique');
                $t->unique(['company_id', 'uuid'], 'sacco_savings_products_uuid_unique');
                $t->index(['company_id', 'server_seq'], 'sacco_savings_products_seq_idx');
            });
        }

        if (! Schema::hasTable('sacco_savings_product_versions')) {
            Schema::create('sacco_savings_product_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('product_id');
                $t->unsignedInteger('terms_version');
                $t->date('effective_from');
                $t->json('terms');
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('created_at')->nullable();

                $t->unique(['product_id', 'terms_version'], 'sacco_savings_product_versions_unique');
                $t->index(['company_id', 'product_id'], 'sacco_savings_product_versions_product_idx');
            });
        }

        if (! Schema::hasTable('sacco_savings_accounts')) {
            Schema::create('sacco_savings_accounts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('account_no', 24); // NumberSequencer kind sav: SAV-000123
                $t->unsignedBigInteger('member_id');
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('ledger_account_id'); // the product's control account, copied so the sub-ledger lock reads one row
                $t->string('status', 10)->default('active'); // active | dormant | frozen | closed
                $t->date('opened_on');
                $t->date('closed_on')->nullable();
                $t->date('maturity_on')->nullable();
                $t->date('dormant_since')->nullable();
                $t->bigInteger('balance_minor')->default(0); // cache: PostingService only
                $t->unsignedBigInteger('held_minor')->default(0); // liens (guarantees, P2)
                $t->date('last_activity_on')->nullable();
                $t->string('frozen_reason', 255)->nullable();
                $t->string('closed_reason', 255)->nullable();
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

                $t->unique(['company_id', 'account_no'], 'sacco_savings_accounts_no_unique');
                $t->unique(['company_id', 'uuid'], 'sacco_savings_accounts_uuid_unique');
                $t->index(['company_id', 'member_id'], 'sacco_savings_accounts_member_idx');
                $t->index(['company_id', 'product_id', 'status'], 'sacco_savings_accounts_product_idx');
                $t->index(['company_id', 'server_seq'], 'sacco_savings_accounts_seq_idx');
            });
        }

        if (! Schema::hasTable('sacco_transactions')) {
            Schema::create('sacco_transactions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('receipt_no', 30)->nullable(); // NumberSequencer kind rct: RCT-2026-000123
                $t->string('device_receipt_no', 40)->nullable(); // the phone's own number, printed offline
                $t->string('kind', 24);
                $t->string('channel', 10)->default('cash'); // cash | bank | momo | checkoff | internal
                $t->unsignedBigInteger('member_id')->nullable(); // null only for a held receipt whose member is unknown
                $t->unsignedBigInteger('savings_account_id')->nullable();
                $t->unsignedBigInteger('share_account_id')->nullable();
                $t->unsignedBigInteger('loan_id')->nullable();
                $t->unsignedBigInteger('counter_account_id')->nullable(); // the other side of a transfer
                $t->unsignedInteger('shares_count')->nullable();
                $t->unsignedBigInteger('amount_minor');
                $t->unsignedBigInteger('fee_minor')->default(0);
                $t->char('currency', 3);
                $t->date('value_date');
                $t->string('status', 18); // posted | held | pending_approval | rejected | cancelled
                $t->string('hold_reason', 40)->nullable(); // unknown_account | account_closed | over_offline_max | loan_closed
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->unsignedBigInteger('reverses_id')->nullable(); // a transaction is reversed once
                $t->string('reason', 255)->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('momo_request_id')->nullable();
                $t->unsignedBigInteger('payout_id')->nullable();
                $t->string('reference', 80)->nullable();
                $t->string('narration', 255)->nullable();
                $t->unsignedBigInteger('received_by_id')->nullable();
                $t->unsignedBigInteger('member_actor_id')->nullable();
                $t->json('meta')->nullable(); // what a held receipt said (the phone's account uuids) until it is allocated
                $t->timestamps();
                $t->string('uuid', 36);
                $t->unsignedBigInteger('server_seq')->default(0);
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_deleted')->default(false);
                $t->unsignedBigInteger('client_created_at')->nullable();
                $t->unsignedBigInteger('client_updated_at')->nullable();
                $t->string('device_id', 64)->nullable();

                $t->unique(['company_id', 'uuid'], 'sacco_transactions_uuid_unique');
                $t->unique(['company_id', 'receipt_no'], 'sacco_transactions_receipt_unique');
                $t->unique('reverses_id', 'sacco_transactions_reverses_unique');
                $t->index(['company_id', 'member_id', 'value_date'], 'sacco_transactions_member_idx');
                $t->index(['company_id', 'kind', 'status'], 'sacco_transactions_kind_idx');
                $t->index(['company_id', 'savings_account_id', 'status'], 'sacco_transactions_savings_idx');
                $t->index(['company_id', 'journal_entry_id'], 'sacco_transactions_entry_idx');
                $t->index(['company_id', 'server_seq'], 'sacco_transactions_seq_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_transactions');
        Schema::dropIfExists('sacco_savings_accounts');
        Schema::dropIfExists('sacco_savings_product_versions');
        Schema::dropIfExists('sacco_savings_products');
    }
};

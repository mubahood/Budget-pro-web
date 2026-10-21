<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P4 (docs/akiba/04-architecture.md §3.6, ADR 0009 §8): the year-end, the money runs and the returns.
 *
 * - sacco_interest_runs / _lines (P4.6): savings interest, one accrual run per product and month (Dr 5100 / Cr 2140,
 *   key sint:{run_id}) and one credit run per product and posting period (Dr 2140 / Cr each account's 21x0). A line is
 *   one account's share of its run, allocated exactly (largest remainder), credited once (credited_run_id).
 * - sacco_fiscal_years (P4.7): a closed year's income, expenses and net surplus, and its closing entry (close:{year}).
 * - sacco_appropriations (P4.7): the waterfall the AGM adopted (reserve, education, transfer fund, other reserves,
 *   dividend pool, retained), frozen in an approval and posted once (approp:{year}).
 * - sacco_dividend_runs / _lines (P4.8): the dividend paid out per member (to savings, in cash or by MoMo), the pool
 *   allocated exactly over the members' shares.
 * - sacco_stf_buybacks (P4.9): shares bought back from a leaving member by the Share Transfer Fund.
 * - sacco_dormancy_notices (P4.10): each step of a dormant account's timeline.
 * - sacco_return_filings (P4.2, P4.5): each return owed, generated and filed, with its figures as generated.
 *
 * Money is BIGINT minor units, rates basis points (AK-08). The runs and filings are written by their services only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_interest_runs')) {
            Schema::create('sacco_interest_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('product_id');
                $t->string('kind', 10); // accrual | credit
                $t->date('period_from');
                $t->date('period_to');
                $t->unsignedInteger('rate_bp')->default(0); // annual, as in force at the period's end (accrual)
                $t->string('basis', 20)->default('none'); // daily_balance | min_monthly_balance (accrual)
                $t->unsignedInteger('accounts_count')->default(0);
                $t->unsignedBigInteger('total_minor')->default(0);
                $t->unsignedBigInteger('released_minor')->default(0); // credit: accrued interest of accounts closed before it was credited
                $t->unsignedBigInteger('entry_id')->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'product_id', 'kind', 'period_from'], 'sacco_interest_runs_unique');
                $t->index(['company_id', 'period_to'], 'sacco_interest_runs_period_idx');
            });
        }
        if (! Schema::hasTable('sacco_interest_run_lines')) {
            Schema::create('sacco_interest_run_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('run_id');
                $t->unsignedBigInteger('savings_account_id');
                $t->unsignedBigInteger('member_id')->nullable();
                $t->unsignedBigInteger('basis_minor')->default(0); // balance-days (daily) or the lowest balance (monthly minimum)
                $t->unsignedSmallInteger('days')->default(0);
                $t->unsignedBigInteger('amount_minor')->default(0);
                $t->unsignedBigInteger('credited_run_id')->nullable(); // accrual lines: the credit run that paid them
                $t->boolean('released')->default(false); // the account had closed: the accrual went back to the expense
                $t->timestamps();

                $t->index(['company_id', 'run_id'], 'sacco_interest_run_lines_run_idx');
                $t->index(['company_id', 'savings_account_id', 'credited_run_id'], 'sacco_interest_run_lines_account_idx');
            });
        }
        if (! Schema::hasTable('sacco_fiscal_years')) {
            Schema::create('sacco_fiscal_years', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedSmallInteger('year'); // the year it ends in
                $t->date('starts_on');
                $t->date('ends_on');
                $t->string('status', 10)->default('open'); // open | closing | closed
                $t->bigInteger('income_minor')->default(0);
                $t->bigInteger('expense_minor')->default(0);
                $t->bigInteger('net_surplus_minor')->default(0); // negative: a deficit
                $t->unsignedBigInteger('close_entry_id')->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->date('closed_on')->nullable();
                $t->unsignedBigInteger('closed_by_id')->nullable();
                $t->date('appropriated_on')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'year'], 'sacco_fiscal_years_unique');
            });
        }
        if (! Schema::hasTable('sacco_appropriations')) {
            Schema::create('sacco_appropriations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('fiscal_year_id');
                $t->bigInteger('surplus_minor');
                $t->json('lines'); // [{key, label, account (system key), bp?, amount_minor}]
                $t->unsignedInteger('dividend_rate_bp')->default(0);
                $t->string('dividend_basis', 20)->nullable(); // year_end_shares | weighted_monthly
                $t->unsignedBigInteger('dividend_pool_minor')->default(0);
                $t->date('agm_date')->nullable();
                $t->unsignedBigInteger('agm_minutes_file_id')->nullable();
                $t->string('status', 12)->default('requested'); // requested | posted | rejected | cancelled | expired
                $t->json('warnings')->nullable(); // for a SACCO the law's limits do not bind: what the report shows
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('entry_id')->nullable();
                $t->date('posted_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'fiscal_year_id'], 'sacco_appropriations_year_idx');
            });
        }
        if (! Schema::hasTable('sacco_dividend_runs')) {
            Schema::create('sacco_dividend_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('fiscal_year_id');
                $t->unsignedBigInteger('appropriation_id');
                $t->unsignedInteger('rate_bp');
                $t->string('basis', 20); // year_end_shares | weighted_monthly
                $t->unsignedBigInteger('pool_minor');
                $t->unsignedBigInteger('basis_total_minor');
                $t->unsignedInteger('members_count')->default(0);
                $t->string('pay_to', 10)->default('savings'); // savings | cash | momo (a member without one is paid in cash)
                $t->integer('core_capital_ratio_bp_after')->nullable();
                $t->unsignedBigInteger('registrar_consent_file_id')->nullable();
                $t->date('registrar_consent_on')->nullable();
                $t->string('registrar_consent_ref', 80)->nullable();
                $t->string('status', 12)->default('requested'); // requested | paid | rejected | cancelled | expired
                $t->json('warnings')->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('entry_id')->nullable(); // savings and cash lines, one entry
                $t->date('paid_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'fiscal_year_id'], 'sacco_dividend_runs_year_idx');
            });
        }
        if (! Schema::hasTable('sacco_dividend_lines')) {
            Schema::create('sacco_dividend_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('run_id');
                $t->unsignedBigInteger('member_id');
                $t->unsignedBigInteger('share_account_id')->nullable();
                $t->unsignedBigInteger('basis_minor');
                $t->unsignedBigInteger('amount_minor');
                $t->string('paid_to', 10); // savings | cash | momo
                $t->unsignedBigInteger('savings_account_id')->nullable();
                $t->unsignedBigInteger('payout_id')->nullable();
                $t->string('status', 10)->default('due'); // due | paid | sent | failed
                $t->date('paid_on')->nullable();
                $t->timestamps();

                $t->unique(['run_id', 'member_id'], 'sacco_dividend_lines_member_unique');
                $t->index(['company_id', 'member_id'], 'sacco_dividend_lines_member_idx');
            });
        }
        if (! Schema::hasTable('sacco_stf_buybacks')) {
            Schema::create('sacco_stf_buybacks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id');
                $t->unsignedBigInteger('share_account_id');
                $t->unsignedInteger('shares_count');
                $t->unsignedBigInteger('amount_minor');
                $t->string('channel', 10); // cash | bank
                $t->unsignedSmallInteger('fiscal_year');
                $t->string('status', 12)->default('requested'); // requested | posted | rejected | cancelled | expired
                $t->string('reason', 255)->nullable();
                $t->json('warnings')->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('entry_id')->nullable();
                $t->date('posted_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->index(['company_id', 'fiscal_year', 'status'], 'sacco_stf_buybacks_year_idx');
            });
        }
        if (! Schema::hasTable('sacco_dormancy_notices')) {
            Schema::create('sacco_dormancy_notices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('savings_account_id');
                $t->unsignedBigInteger('member_id');
                $t->string('stage', 12); // register | notice | advertised | transferred
                $t->date('on');
                $t->unsignedBigInteger('balance_minor')->default(0);
                $t->string('reference', 120)->nullable(); // the advertisement or the transfer's reference
                $t->unsignedBigInteger('file_id')->nullable();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('entry_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'savings_account_id', 'stage', 'on'], 'sacco_dormancy_notices_unique');
                $t->index(['company_id', 'stage'], 'sacco_dormancy_notices_stage_idx');
            });
        }
        if (! Schema::hasTable('sacco_return_filings')) {
            Schema::create('sacco_return_filings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('form', 12); // F1–F7, SAV, REG_ANNUAL, S55AS
                $t->string('period_key', 10); // 2026-09, 2026-Q3, 2026
                $t->date('period_from');
                $t->date('period_to');
                $t->date('due_on');
                $t->string('status', 10)->default('due'); // due | generated | filed
                $t->json('figures')->nullable(); // the form's lines as last generated
                $t->timestamp('generated_at')->nullable();
                $t->unsignedBigInteger('generated_by_id')->nullable();
                $t->json('file_ids')->nullable();
                $t->unsignedBigInteger('authorised_by_id')->nullable();
                $t->unsignedBigInteger('countersigned_by_id')->nullable();
                $t->date('filed_on')->nullable();
                $t->string('filed_reference', 120)->nullable();
                $t->unsignedBigInteger('filed_by_id')->nullable();
                $t->json('reminded')->nullable(); // the days a reminder went out
                $t->timestamps();

                $t->unique(['company_id', 'form', 'period_key'], 'sacco_return_filings_unique');
                $t->index(['company_id', 'status', 'due_on'], 'sacco_return_filings_due_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        foreach (['sacco_return_filings', 'sacco_dormancy_notices', 'sacco_stf_buybacks', 'sacco_dividend_lines', 'sacco_dividend_runs', 'sacco_appropriations',
            'sacco_fiscal_years', 'sacco_interest_run_lines', 'sacco_interest_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

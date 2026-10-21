<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P2.3 (docs/akiba/04-architecture.md §3.4, AK-07, AK-26, AK-28, AK-49, AK-50): a SACCO's loan
 * products. Rates are whole basis points (the contract rate per month or year; the penalty per month), money is
 * BIGINT minor units. A loan copies its product's terms when it is applied for (sacco_loans.terms), so changing a
 * product never changes a running loan; terms_version counts the changes. The legal guards are
 * App\Support\Rules\SaccoLoanProductRules. Pull-only sync columns let the phone start a draft on a real product.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_loan_products')) {
            Schema::create('sacco_loan_products', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('code', 16);
                $t->string('name', 80);
                $t->string('description', 255)->nullable();
                $t->string('preset', 12)->nullable(); // pdm | emyooga | vsla
                $t->string('interest_method', 24); // reducing_annuity | reducing_equal_principal | flat (group mode only)
                $t->unsignedInteger('rate_bp'); // per rate_period
                $t->string('rate_period', 6)->default('month'); // month | year
                $t->string('repayment_frequency', 12)->default('monthly'); // weekly | fortnightly | monthly
                $t->unsignedSmallInteger('min_term');
                $t->unsignedSmallInteger('max_term');
                $t->unsignedBigInteger('min_amount_minor');
                $t->unsignedBigInteger('max_amount_minor');
                $t->unsignedInteger('savings_multiplier_bp')->default(0); // 30000 = 3 × free savings; 0 = no savings limit
                $t->unsignedInteger('min_membership_days')->default(0);
                $t->unsignedInteger('min_shares')->default(0);
                $t->unsignedTinyInteger('guarantors_required')->default(0);
                $t->unsignedInteger('guarantee_cover_bp')->default(10000); // share of (principal − own free savings) to guarantee
                $t->unsignedTinyInteger('max_guarantees_per_guarantor')->default(3);
                $t->unsignedInteger('processing_fee_bp')->default(0);
                $t->unsignedBigInteger('processing_fee_minor')->default(0);
                $t->unsignedInteger('insurance_bp')->default(0);
                $t->unsignedInteger('penalty_rate_bp')->default(0); // per month, on overdue principal (≤ ½ the monthly rate)
                $t->unsignedSmallInteger('grace_days')->default(0);
                $t->unsignedSmallInteger('principal_grace_periods')->default(0);
                $t->unsignedTinyInteger('cooling_off_working_days')->default(5);
                $t->unsignedInteger('cancellation_fee_bp')->default(0); // ≤ 200
                $t->boolean('hold_disbursement_until_cooling_off_ends')->default(false);
                $t->json('allowed_channels'); // savings | cash | bank | momo
                $t->json('collateral_types')->nullable(); // what this product accepts (never ID, passport, ATM card, passbook)
                $t->unsignedInteger('inputs_share_min_bp')->nullable(); // PDM: at least 9000 of the loan on inputs
                $t->boolean('is_salary_advance')->default(false); // the one product an employee may take (AK-43)
                $t->boolean('allow_top_up')->default(true);
                $t->unsignedBigInteger('portfolio_account_id'); // 1300
                $t->unsignedBigInteger('interest_income_account_id'); // 4100
                $t->unsignedBigInteger('fee_income_account_id'); // 4200
                $t->unsignedBigInteger('penalty_income_account_id')->nullable(); // 4110 (office mode)
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('terms_version')->default(1);
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

                $t->unique(['company_id', 'code'], 'sacco_loan_products_code_unique');
                $t->unique(['company_id', 'uuid'], 'sacco_loan_products_uuid_unique');
                $t->index(['company_id', 'is_active'], 'sacco_loan_products_active_idx');
                $t->index(['company_id', 'server_seq'], 'sacco_loan_products_seq_idx');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_loan_products');
    }
};

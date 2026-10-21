<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P1.7 (docs/akiba/04-architecture.md §3.1): one row of SACCO settings per company, written by
 * SaccoSetupService::setup and changed through SaccoSettingsService. The mode is fixed once the first journal is
 * posted (AK-05) and the currency at setup; money is in minor units, rates in basis points.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_settings')) {
            Schema::create('sacco_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->unique();
                $t->string('mode', 10); // office | group
                $t->string('legal_name', 190);
                $t->string('short_name', 60);
                $t->string('registration_no', 60)->nullable(); // Cooperative "CS No."
                $t->string('licence_no', 60)->nullable(); // Tier 4 licence; empty = not licensed
                $t->date('licence_expires_on')->nullable();
                $t->string('regulator_name', 190)->nullable(); // null = config('sacco.regulator') (AK-14)
                $t->string('regulator_short', 30)->nullable();
                $t->string('regulator_portal_url', 190)->nullable();
                $t->boolean('show_regulated_line')->default(false); // only with a licence number
                $t->unsignedTinyInteger('fiscal_year_start_month')->default(1);
                $t->char('currency', 3); // the company's at setup; fixed afterwards
                $t->unsignedBigInteger('share_par_minor');
                $t->unsignedInteger('min_shares');
                $t->unsignedInteger('max_holding_bp')->default(3333);
                $t->unsignedBigInteger('entrance_fee_minor');
                $t->unsignedBigInteger('withdrawal_approval_above_minor');
                $t->unsignedBigInteger('offline_receipt_max_minor');
                $t->boolean('single_officer_mode')->default(false); // group mode only (AK-22)
                $t->string('receipts_channel', 12)->default('whatsapp'); // sms | whatsapp | app | none
                $t->unsignedInteger('dividend_cap_bp')->default(1000);
                $t->unsignedInteger('reserve_min_bp')->default(1000);
                $t->unsignedInteger('education_fund_bp')->default(100);
                $t->unsignedTinyInteger('dormancy_months')->default(24);
                $t->string('employee_loans_policy', 20)->default('salary_advance_only'); // salary_advance_only | committee (AK-43)
                $t->string('allocation_order', 60)->default('penalty,fees,interest,principal'); // AK-27
                $t->unsignedBigInteger('set_up_by_id')->nullable();
                $t->timestamps();
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_settings');
    }
};

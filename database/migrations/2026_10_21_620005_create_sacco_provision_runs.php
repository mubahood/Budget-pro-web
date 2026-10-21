<?php

use App\Support\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akiba Ledger P2.12 (04 §5.3, AK-40): one row per SACCO and month for the loan-loss provisioning run: the required
 * allowance per class (the SI 51 table × outstanding principal), what 1390 held, the delta posted (key
 * prov:{yyyymm}) and its entry. A month whose run is missing cannot close; a run with no change still leaves its row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_provision_runs')) {
            Schema::create('sacco_provision_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->char('month', 7); // YYYY-MM
                $t->json('by_class'); // class => [loans, principal_minor, rate_bp, required_minor]
                $t->unsignedBigInteger('required_minor');
                $t->bigInteger('previous_minor');
                $t->bigInteger('delta_minor');
                $t->unsignedBigInteger('entry_id')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'month'], 'sacco_provision_runs_month_unique');
            });
        }

        SchemaIntegrity::run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_provision_runs');
    }
};

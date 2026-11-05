<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package C of the landlord and commission round: payout runs (LC-09); what a payout keeps of itself for its advice (LC-10) and its advance
 * (LC-11), written once when it is recorded; a landlord's second phone (LC-24). Additive and safe on a live database: old payouts stay null
 * or zero and print without those lines. Nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_payout_runs')) {
            Schema::create('rental_payout_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('number', 20);
                $t->string('status', 10)->default('draft'); // draft | posted | cancelled
                $t->date('paid_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('posted_by_id')->nullable();
                $t->timestamp('posted_at')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'number'], 'rental_payout_runs_number_unique');
                $t->index(['company_id', 'status'], 'rental_payout_runs_status_idx');
            });
        }

        if (! Schema::hasTable('rental_payout_run_lines')) {
            Schema::create('rental_payout_run_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('run_id');
                $t->unsignedBigInteger('landlord_id');
                $t->unsignedBigInteger('amount_minor');
                $t->string('method', 14);
                $t->string('reference', 100)->nullable();
                $t->bigInteger('balance_at_draft');
                $t->unsignedBigInteger('payout_id')->nullable();
                $t->timestamps();
                $t->unique(['run_id', 'landlord_id'], 'rental_payout_run_lines_landlord_unique');
                $t->index(['company_id', 'run_id'], 'rental_payout_run_lines_run_idx');
            });
        }

        Schema::table('rental_payouts', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_payouts', 'run_id')) {
                $t->unsignedBigInteger('run_id')->nullable();
            }
            if (! Schema::hasColumn('rental_payouts', 'paid_to_snapshot')) {
                $t->string('paid_to_snapshot', 500)->nullable();
            }
            if (! Schema::hasColumn('rental_payouts', 'balance_before_minor')) {
                $t->bigInteger('balance_before_minor')->nullable();
            }
            if (! Schema::hasColumn('rental_payouts', 'balance_after_minor')) {
                $t->bigInteger('balance_after_minor')->nullable();
            }
            if (! Schema::hasColumn('rental_payouts', 'advance_minor')) {
                $t->unsignedBigInteger('advance_minor')->default(0);
            }
            if (! Schema::hasColumn('rental_payouts', 'advance_reason')) {
                $t->string('advance_reason', 255)->nullable();
            }
        });

        Schema::table('rental_landlords', function (Blueprint $t) {
            if (! Schema::hasColumn('rental_landlords', 'phone_2')) {
                $t->string('phone_2', 40)->nullable();
            }
            if (! Schema::hasColumn('rental_landlords', 'phone_2_e164')) {
                $t->string('phone_2_e164', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Additive and live: nothing to take back.
    }
};

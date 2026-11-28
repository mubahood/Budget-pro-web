<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contributions (dues) in the shared sacco code, a family's announcements, and the person a sign-in is.
 *
 * - sacco_dues_programmes: what people are asked to give (recurring or once; fixed, any or personal amounts).
 * - sacco_dues_lines: one per person per period, built by `sacco:dues-prepare`; unique (programme, member, period).
 * - sacco_dues_payments: money received. Never edited or deleted: a reversal is a second row with the negative amount
 *   (reverses_id), and every total is summed from these rows.
 * - kin_announcements: news for the whole family (soft deleted).
 * - sacco_members.user_id: the sign-in that is this person, for "my dues".
 *
 * Money in minor units. Safe to run again. API only: not in the sync registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacco_dues_programmes')) {
            Schema::create('sacco_dues_programmes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('name', 120);
                $t->string('kind', 10); // recurring | once
                $t->string('period', 10)->nullable(); // weekly | monthly | yearly (recurring only)
                $t->string('amount_mode', 10); // fixed | any | personal
                $t->bigInteger('amount_minor')->default(0);
                $t->bigInteger('target_minor')->nullable();
                $t->date('starts_on');
                $t->date('ends_on')->nullable();
                $t->string('status', 10)->default('active'); // active | closed
                $t->string('description', 1000)->nullable();
                $t->boolean('allow_advance')->default(false);
                $t->timestamp('closed_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'status'], 'sacco_dues_programmes_company_status_idx');
            });
        }

        if (! Schema::hasTable('sacco_dues_lines')) {
            Schema::create('sacco_dues_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('programme_id');
                $t->unsignedBigInteger('member_id'); // sacco_members
                $t->string('period_key', 10); // 2026-11 | 2026-W48 | 2026 | once
                $t->date('period_start');
                $t->date('period_end')->nullable();
                $t->bigInteger('expected_minor')->default(0);
                $t->timestamps();
                $t->unique(['programme_id', 'member_id', 'period_key'], 'sacco_dues_lines_programme_member_period_unique');
                $t->index(['company_id', 'member_id'], 'sacco_dues_lines_company_member_idx');
            });
        }

        if (! Schema::hasTable('sacco_dues_payments')) {
            Schema::create('sacco_dues_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('programme_id');
                $t->unsignedBigInteger('member_id'); // whose dues
                $t->bigInteger('amount_minor'); // negative on a reversal
                $t->date('paid_on');
                $t->string('method', 16); // cash | mobile_money | bank
                $t->string('reference', 100)->nullable();
                $t->unsignedBigInteger('paid_by_member_id')->nullable(); // someone paying for them
                $t->string('note', 255)->nullable();
                $t->string('receipt_no', 30);
                $t->unsignedBigInteger('reverses_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->string('reason', 255)->nullable();
                $t->unsignedBigInteger('recorded_by_id')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'receipt_no'], 'sacco_dues_payments_company_receipt_unique');
                $t->unique('reverses_id', 'sacco_dues_payments_reverses_unique'); // a payment is reversed once
                $t->index(['programme_id', 'member_id'], 'sacco_dues_payments_programme_member_idx');
                $t->index(['company_id', 'paid_on'], 'sacco_dues_payments_company_paid_idx');
            });
        }

        if (! Schema::hasTable('kin_announcements')) {
            Schema::create('kin_announcements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('title', 150);
                $t->text('body')->nullable();
                $t->boolean('pinned')->default(false);
                $t->unsignedBigInteger('author_user_id')->nullable();
                $t->timestamp('published_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'published_at'], 'kin_announcements_company_published_idx');
            });
        }

        if (! Schema::hasColumn('sacco_members', 'user_id')) {
            Schema::table('sacco_members', function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->nullable(); // admin_users: the sign-in that is this person
                $t->index(['company_id', 'user_id'], 'sacco_members_company_user_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kin_announcements');
        Schema::dropIfExists('sacco_dues_payments');
        Schema::dropIfExists('sacco_dues_lines');
        Schema::dropIfExists('sacco_dues_programmes');
        if (Schema::hasColumn('sacco_members', 'user_id')) {
            Schema::table('sacco_members', function (Blueprint $t) {
                $t->dropIndex('sacco_members_company_user_idx');
                $t->dropColumn('user_id');
            });
        }
    }
};

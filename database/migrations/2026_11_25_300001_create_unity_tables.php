<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UnityDesk (the unity module): members, categories, subscriptions and chapters. An association's members are sacco_members
 * rows (never deleted; a member who leaves is exited), like a SACCO's and a family's. Added here:
 *
 * - sacco_members: chapter_id, category_id, member_since, and two caches only the server writes (App\Services\Unity\Memberships,
 *   SyncRegistry::DERIVED_COLUMNS): membership_ends_on (the end of the last period in force; null with standing life) and
 *   standing (good | lapsed | life; null until the first subscription).
 * - unity_categories: a membership category and its fee for a period in months (0 = life).
 * - unity_subscriptions: one period of a member's membership and what was paid for it (amounts in minor units).
 * - unity_chapters: an association's chapters (branches), each perhaps with its lead.
 *
 * The three tables carry the sync columns (ADR 0001). No JSON defaults, no recursive SQL (MySQL 5.7). Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacco_members', function (Blueprint $t) {
            $add = fn (string $column, callable $make) => Schema::hasColumn('sacco_members', $column) ? null : $make();
            $add('chapter_id', fn () => $t->unsignedBigInteger('chapter_id')->nullable()); // unity_chapters
            $add('category_id', fn () => $t->unsignedBigInteger('category_id')->nullable()); // unity_categories
            $add('member_since', fn () => $t->date('member_since')->nullable());
            $add('membership_ends_on', fn () => $t->date('membership_ends_on')->nullable());
            $add('standing', fn () => $t->string('standing', 8)->nullable()); // good | lapsed | life
        });
        Schema::table('sacco_members', function (Blueprint $t) {
            if (! $this->hasIndex('sacco_members', 'sacco_members_company_chapter_idx')) {
                $t->index(['company_id', 'chapter_id'], 'sacco_members_company_chapter_idx');
            }
            if (! $this->hasIndex('sacco_members', 'sacco_members_company_standing_idx')) {
                $t->index(['company_id', 'standing', 'membership_ends_on'], 'sacco_members_company_standing_idx');
            }
        });

        if (! Schema::hasTable('unity_categories')) {
            Schema::create('unity_categories', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('name', 120);
                $t->bigInteger('fee_minor')->default(0);
                $t->unsignedSmallInteger('period_months')->default(12); // 0 = life
                $t->text('benefits')->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $this->syncColumns($t);

                $t->unique(['company_id', 'uuid'], 'unity_categories_company_uuid_unique');
                $t->index(['company_id', 'server_seq'], 'unity_categories_company_seq_idx');
                $t->index(['company_id', 'is_active', 'sort_order'], 'unity_categories_company_active_idx');
            });
        }

        if (! Schema::hasTable('unity_chapters')) {
            Schema::create('unity_chapters', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('name', 120);
                $t->unsignedBigInteger('lead_member_id')->nullable(); // sacco_members
                $t->string('location', 150)->nullable();
                $t->string('notes', 500)->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $this->syncColumns($t);

                $t->unique(['company_id', 'uuid'], 'unity_chapters_company_uuid_unique');
                $t->index(['company_id', 'server_seq'], 'unity_chapters_company_seq_idx');
                $t->index(['company_id', 'name'], 'unity_chapters_company_name_idx');
            });
        }

        if (! Schema::hasTable('unity_subscriptions')) {
            Schema::create('unity_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('member_id'); // sacco_members
                $t->unsignedBigInteger('category_id'); // unity_categories
                $t->date('starts_on');
                $t->date('ends_on')->nullable(); // null: for life
                $t->bigInteger('amount_minor')->default(0);
                $t->bigInteger('paid_minor')->default(0);
                $t->date('paid_on')->nullable();
                $t->string('method', 20)->nullable(); // cash | mobile_money | bank | cheque | other
                $t->string('reference', 100)->nullable();
                $t->string('receipt_no', 40)->nullable();
                $t->string('status', 10)->default('pending'); // pending | paid | waived | cancelled
                $t->string('notes', 500)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $this->syncColumns($t);

                $t->unique(['company_id', 'uuid'], 'unity_subscriptions_company_uuid_unique');
                $t->index(['company_id', 'server_seq'], 'unity_subscriptions_company_seq_idx');
                $t->index(['company_id', 'member_id', 'ends_on'], 'unity_subscriptions_company_member_idx');
                $t->index(['company_id', 'status', 'paid_on'], 'unity_subscriptions_company_paid_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unity_subscriptions');
        Schema::dropIfExists('unity_chapters');
        Schema::dropIfExists('unity_categories');
        Schema::table('sacco_members', function (Blueprint $t) {
            foreach (['sacco_members_company_chapter_idx', 'sacco_members_company_standing_idx'] as $index) {
                if ($this->hasIndex('sacco_members', $index)) {
                    $t->dropIndex($index);
                }
            }
        });
        $columns = array_values(array_filter(['chapter_id', 'category_id', 'member_since', 'membership_ends_on', 'standing'], fn ($c) => Schema::hasColumn('sacco_members', $c)));
        if ($columns !== []) {
            Schema::table('sacco_members', fn (Blueprint $t) => $t->dropColumn($columns));
        }
    }

    /** The sync columns (ADR 0001). */
    private function syncColumns(Blueprint $t): void
    {
        $t->string('uuid', 36);
        $t->unsignedBigInteger('server_seq')->default(0);
        $t->unsignedInteger('version')->default(1);
        $t->boolean('is_deleted')->default(false);
        $t->unsignedBigInteger('client_created_at')->nullable();
        $t->unsignedBigInteger('client_updated_at')->nullable();
        $t->string('device_id', 64)->nullable();
    }

    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('index_name', $name)->exists();
    }
};

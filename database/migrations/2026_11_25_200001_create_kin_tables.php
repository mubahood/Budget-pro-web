<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * My Family Roots 360 (the kin module), people and tree. A family's people are sacco_members rows (status active, applicant,
 * deceased or exited; never deleted), so a family that later keeps a purse has them already. Added here:
 *
 * - sacco_members: the family's own columns. generation (founders 1) and root_id (the founder a line goes back to) are worked
 *   out by App\Services\Kin\TreeService, never sent by a phone (SyncRegistry::DERIVED_COLUMNS). JSON columns have no default
 *   (MySQL 5.7).
 * - kin_links: child to parent (father, mother or an unsaid parent; birth, adoptive, step or guardian).
 * - kin_spouses: a marriage, to a member or to a name only.
 * - kin_branches: a family's branches (houses), each perhaps with its founder.
 *
 * The three tables carry the sync columns (ADR 0001). Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacco_members', function (Blueprint $t) {
            $add = fn (string $column, callable $make) => Schema::hasColumn('sacco_members', $column) ? null : $make();
            $add('date_of_death', fn () => $t->date('date_of_death')->nullable());
            $add('place_of_death', fn () => $t->string('place_of_death', 150)->nullable());
            $add('birth_date_precision', fn () => $t->string('birth_date_precision', 8)->nullable()); // exact | year | about (null: exact)
            $add('education_level', fn () => $t->string('education_level', 60)->nullable());
            $add('country', fn () => $t->string('country', 60)->nullable());
            $add('heritage', fn () => $t->json('heritage')->nullable()); // {clan, totem, tribe, origin, ...}
            $add('in_purse', fn () => $t->boolean('in_purse')->default(false)); // a member of the family's purse (kin + sacco)
            $add('dues_duty', fn () => $t->string('dues_duty', 12)->nullable()); // compulsory | optional | exempt
            $add('dues_personal_minor', fn () => $t->bigInteger('dues_personal_minor')->nullable());
            $add('dues_start_date', fn () => $t->date('dues_start_date')->nullable());
            $add('branch_id', fn () => $t->unsignedBigInteger('branch_id')->nullable());
            $add('generation', fn () => $t->unsignedSmallInteger('generation')->nullable());
            $add('root_id', fn () => $t->unsignedBigInteger('root_id')->nullable());
            $add('privacy', fn () => $t->json('privacy')->nullable()); // {phone: family|officers|hidden, ...}
        });
        Schema::table('sacco_members', function (Blueprint $t) {
            if (! $this->hasIndex('sacco_members', 'sacco_members_company_branch_idx')) {
                $t->index(['company_id', 'branch_id'], 'sacco_members_company_branch_idx');
            }
            if (! $this->hasIndex('sacco_members', 'sacco_members_company_generation_idx')) {
                $t->index(['company_id', 'generation'], 'sacco_members_company_generation_idx');
            }
        });

        if (! Schema::hasTable('kin_branches')) {
            Schema::create('kin_branches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('name', 120);
                $t->unsignedBigInteger('founder_id')->nullable(); // sacco_members
                $t->unsignedBigInteger('coordinator_user_id')->nullable(); // admin_users (a kin_branch_coordinator)
                $t->string('notes', 500)->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $this->syncColumns($t);

                $t->unique(['company_id', 'uuid'], 'kin_branches_company_uuid_unique');
                $t->index(['company_id', 'server_seq'], 'kin_branches_company_seq_idx');
                $t->index(['company_id', 'name'], 'kin_branches_company_name_idx');
            });
        }

        if (! Schema::hasTable('kin_links')) {
            Schema::create('kin_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('child_id'); // sacco_members
                $t->unsignedBigInteger('parent_id'); // sacco_members
                $t->string('role', 8); // father | mother | parent
                $t->string('kind', 10)->default('birth'); // birth | adoptive | step | guardian
                $t->string('notes', 255)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $this->syncColumns($t);

                $t->unique(['company_id', 'uuid'], 'kin_links_company_uuid_unique');
                $t->index(['company_id', 'server_seq'], 'kin_links_company_seq_idx');
                $t->index(['company_id', 'child_id'], 'kin_links_company_child_idx');
                $t->index(['company_id', 'parent_id'], 'kin_links_company_parent_idx');
            });
        }

        if (! Schema::hasTable('kin_spouses')) {
            Schema::create('kin_spouses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('person_id'); // sacco_members
                $t->unsignedBigInteger('spouse_id')->nullable(); // sacco_members, or null with spouse_name
                $t->string('spouse_name', 150)->nullable();
                $t->string('status', 8)->default('current'); // current | ended | widowed
                $t->date('married_on')->nullable();
                $t->date('ended_on')->nullable();
                $t->unsignedSmallInteger('sort_order')->default(0); // first wife, second wife, ...
                $t->string('notes', 255)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $this->syncColumns($t);

                $t->unique(['company_id', 'uuid'], 'kin_spouses_company_uuid_unique');
                $t->index(['company_id', 'server_seq'], 'kin_spouses_company_seq_idx');
                $t->index(['company_id', 'person_id'], 'kin_spouses_company_person_idx');
                $t->index(['company_id', 'spouse_id'], 'kin_spouses_company_spouse_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kin_spouses');
        Schema::dropIfExists('kin_links');
        Schema::dropIfExists('kin_branches');
        Schema::table('sacco_members', function (Blueprint $t) {
            foreach (['sacco_members_company_branch_idx', 'sacco_members_company_generation_idx'] as $index) {
                if ($this->hasIndex('sacco_members', $index)) {
                    $t->dropIndex($index);
                }
            }
        });
        $columns = array_values(array_filter(['date_of_death', 'place_of_death', 'birth_date_precision', 'education_level', 'country', 'heritage', 'in_purse', 'dues_duty',
            'dues_personal_minor', 'dues_start_date', 'branch_id', 'generation', 'root_id', 'privacy'], fn ($c) => Schema::hasColumn('sacco_members', $c)));
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
